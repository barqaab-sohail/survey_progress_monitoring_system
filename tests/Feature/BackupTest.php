<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DriveStorageConnection;
use App\Models\User;
use App\Services\Audit\BackupGuard;
use App\Services\Audit\Storage;
use App\Services\Backup\DatabaseExporter;
use App\Services\Backup\Runner;
use App\Services\Backup\Workspace;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_cleanup_requires_fresh_verified_backup_covering_this_database_and_ids(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'action' => 'user.password_reset', 'auditable_type' => User::class, 'auditable_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(Runner::class)->run();
        $workspace = new Workspace;
        $state = $workspace->read('state');
        $this->assertSame($id, $state['latest_zip']['audit_through_id']);
        $this->assertSame((new Storage)->identity(), $state['latest_zip']['database_fingerprint']);
        app(BackupGuard::class)->verify($id);
        foreach ([['audit_through_id' => $id - 1], ['database_fingerprint' => 'different database'], ['uploaded_at' => now()->subHours(49)->toIso8601String()]] as $invalid) {
            $changed = $state;
            $changed['latest_zip'] = array_replace($state['latest_zip'], $invalid);
            $workspace->save('state', $changed);
            try {
                app(BackupGuard::class)->verify($id);
                $this->fail('Unsafe backup receipt must be rejected.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('recent verified backup', $error->getMessage());
            }
        }
    }

    private string $directory;

    private string $fixture;

    private string $asset;

    private array $files = [];

    private array $bodies = [];

    private array $sessions = [];

    private array $logs = [];

    private bool $uploadFails = false;

    private bool $badMetadata = false;

    private int $uploadAttempts = 0;

    private int $sequence = 0;

    private int $exports = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $id = bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().'/spms-backup-'.$id;
        $this->fixture = base_path('tests/Fixtures/simple-code-'.$id);
        $this->asset = public_path('build/backup-fixture-'.$id.'.js');
        (new Filesystem)->ensureDirectoryExists(dirname($this->asset));
        file_put_contents($this->asset, 'deployed application asset');
        foreach (['app.php' => '<?php return true;', 'config/services.php' => '<?php return env("CLIENT_SECRET");',
            '.env' => 'APP_KEY=DO-NOT-BACKUP', '.env.production' => 'DB_PASSWORD=DO-NOT-BACKUP',
            'auth.json' => 'secret', 'service-account.json' => 'secret', 'signing.key' => 'secret',
            'key.properties' => 'secret', 'credentials.php' => 'secret', 'config/secrets.php' => 'secret',
            'storage/app/private/upload.pdf' => 'not code', 'backup-old.zip' => 'previous backup',
            'logs/application.log' => 'log', 'cache/config.php' => 'cached secret', 'tmp/scratch' => 'temp',
            'vendor/autoload.php' => 'dependency', 'node_modules/dep.js' => 'dependency'] as $file => $body) {
            (new Filesystem)->ensureDirectoryExists(dirname($this->fixture.'/'.$file));
            file_put_contents($this->fixture.'/'.$file, $body);
        }
        config(['backups.enabled' => true, 'backups.path' => $this->directory, 'backups.folder_id' => 'backup-folder-12345',
            'backups.min_free_bytes' => 0, 'backups.include' => ['tests/Fixtures/simple-code-'.$id, 'public/build/'.basename($this->asset), 'public/vendor/pdfjs/VERSION'],
            'backups.root_files' => ['composer.lock'], 'services.google_drive.client_id' => 'test-client',
            'services.google_drive.client_secret' => 'private-client-secret']);
        Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) {
            $this->logs[] = [$message, $context];
        });
        Log::shouldReceive('error')->andReturnUsing(function ($message, $context = []) {
            $this->logs[] = [$message, $context];
        });
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        DriveStorageConnection::create(['id' => 1, 'account_id' => 'account-test', 'account_email' => 'storage@example.test',
            'connected_by' => $admin->id, 'token' => ['access_token' => 'expired', 'refresh_token' => 'private-refresh-token', 'expires_at' => 0]]);
        $this->mock(DatabaseExporter::class)->shouldReceive('export')->andReturnUsing(function ($directory) {
            $this->exports++;
            file_put_contents($directory.'/database.sql', 'CREATE TABLE restored (id INTEGER PRIMARY KEY); INSERT INTO restored VALUES (42);');

            return ['driver' => 'mysql'];
        });
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            $id = basename(parse_url($url, PHP_URL_PATH));
            $this->assertNotSame('DELETE', $request->method(), 'No existing Drive backup may be pruned.');
            $this->assertStringNotContainsString('alt=media', $url, 'Do not download existing Drive originals/backups.');
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'refreshed-token', 'expires_in' => 3600]);
            }
            if (str_contains($url, 'generateIds')) {
                return Http::response(['ids' => ['backup-file-'.++$this->sequence]]);
            }
            if ($id === 'backup-folder-12345') {
                return Http::response(['id' => $id, 'mimeType' => 'application/vnd.google-apps.folder']);
            }
            if ($request->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
                $this->uploadAttempts++;
                if ($this->uploadFails) {
                    return Http::response([], 503);
                }
                $id = $request['id'];
                $this->assertSame('application/zip', $request['mimeType']);
                $this->sessions[$id] = ['id' => $id, 'name' => $request['name'], 'parents' => $request['parents']];
                $this->bodies[$id] = '';

                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id='.$id]);
            }
            if ($request->method() === 'PUT') {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $id = $query['upload_id'];
                $this->bodies[$id] .= $request->body();
                $this->files[$id] = $this->sessions[$id] + ['size' => strlen($this->bodies[$id]), 'md5Checksum' => md5($this->bodies[$id])];

                return Http::response(['id' => $id]);
            }
            if (isset($this->files[$id])) {
                $info = $this->files[$id];
                if ($this->badMetadata) {
                    $info['md5Checksum'] = str_repeat('0', 32);
                }

                return Http::response($info);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        // Only the exact temporary fixture and workspace created by this test.
        (new Filesystem)->deleteDirectory($this->directory);
        (new Filesystem)->deleteDirectory($this->fixture);
        if (is_file($this->asset)) {
            unlink($this->asset);
        }
        parent::tearDown();
    }

    public function test_normal_zip_contains_sql_code_locks_assets_and_excludes_secrets_uploads_and_dependencies(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Karachi')->setDate(2026, 10, 29)->setTime(2, 0));
        $this->artisan('backup:run')->assertSuccessful();
        $receipt = (new Workspace)->read('state')['latest_zip'];
        $this->assertSame('backup-2026-10-29-0200.zip', $receipt['name']);
        file_put_contents($this->directory.'/downloaded.zip', $this->bodies[$receipt['file_id']]);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->directory.'/downloaded.zip', \ZipArchive::CHECKCONS));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $prefix = 'application/tests/Fixtures/'.basename($this->fixture).'/';
        $this->assertContains($prefix.'app.php', $entries);
        $this->assertContains($prefix.'config/services.php', $entries);
        $this->assertContains('application/composer.lock', $entries);
        $this->assertContains('application/public/build/'.basename($this->asset), $entries);
        $this->assertContains('application/public/vendor/pdfjs/VERSION', $entries);
        $this->assertCount(6, $entries); // SQL plus the five explicitly allowed files.
        $this->assertFalse($zip->getFromName('manifest.json'));
        $isolated = new \PDO('sqlite::memory:');
        $isolated->exec($zip->getFromName('database.sql'));
        $this->assertSame(42, (int) $isolated->query('SELECT id FROM restored')->fetchColumn());
        $zip->close();
        $this->assertSame([], glob($this->directory.'/run-*'));
        $this->assertSame('refreshed-token', DriveStorageConnection::find(1)->token['access_token']);
        $log = json_encode($this->logs);
        $this->assertStringContainsString('Backup successful.', $log);
        foreach (['private-refresh-token', 'private-client-secret', 'refreshed-token', 'DO-NOT-BACKUP', 'upload_id='] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->travelBack();
    }

    public function test_upload_failure_retains_sql_and_zip_and_preserves_all_previous_backups(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $previous = (new Workspace)->read('state')['latest_zip'];
        file_put_contents($this->directory.'/pending.enc', 'legacy archive');
        $this->uploadFails = true;
        $this->artisan('backup:run')->assertFailed();
        $this->assertSame($previous, (new Workspace)->read('state')['latest_zip']);
        $runs = glob($this->directory.'/run-*');
        $this->assertCount(1, $runs);
        $this->assertFileExists($runs[0].'/database.sql');
        $this->assertCount(1, glob($runs[0].'/*.zip'));
        $this->assertSame('legacy archive', file_get_contents($this->directory.'/pending.enc'));
        $this->assertCount(1, $this->files);
        $this->assertSame(4, $this->uploadAttempts); // One success, three bounded failed attempts.
        $this->assertStringContainsString('Backup failed.', json_encode($this->logs));
    }

    public function test_checksum_failure_retains_local_backup_and_does_not_record_success(): void
    {
        $this->badMetadata = true;
        $this->artisan('backup:run')->assertFailed();
        $this->assertCount(1, glob($this->directory.'/run-*/*.zip'));
        $this->assertArrayNotHasKey('latest_zip', (new Workspace)->read('state'));
    }

    public function test_export_failure_prevents_upload(): void
    {
        $this->app->forgetInstance(DatabaseExporter::class);
        $this->mock(DatabaseExporter::class)->shouldReceive('export')->andThrow(new \RuntimeException('Private SQL exception must not enter logs'));
        $this->artisan('backup:run')->assertFailed();
        $this->assertSame(0, $this->uploadAttempts);
        $this->assertStringNotContainsString('Private SQL exception', json_encode($this->logs));
    }

    public function test_empty_export_prevents_upload(): void
    {
        $this->app->forgetInstance(DatabaseExporter::class);
        $this->mock(DatabaseExporter::class)->shouldReceive('export')->andReturnUsing(function ($directory) {
            touch($directory.'/database.sql');

            return [];
        });
        $this->artisan('backup:run')->assertFailed();
        $this->assertSame(0, $this->uploadAttempts);
    }

    public function test_schedule_uses_pakistan_date_once_daily_and_manual_backup_can_retry(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 29)->setTime(20, 45)); // 01:45 PKT next day.
        $this->artisan('backup:run', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame(0, $this->exports);
        $this->travelTo(now()->setTimezone('UTC')->setTime(21, 0)); // 02:00 PKT.
        $this->artisan('backup:run', ['--scheduled' => true])->assertSuccessful();
        $this->artisan('backup:run', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame(1, $this->exports);
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertSame(2, $this->exports);
        $this->assertCount(2, $this->files); // Same-minute name never overwrites an earlier file ID.
        $this->travelBack();
    }

    public function test_failed_schedule_attempt_is_logged_once_that_day_and_retries_next_day(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Karachi')->setTime(2, 0));
        $this->uploadFails = true;
        $this->artisan('backup:run', ['--scheduled' => true])->assertFailed();
        $this->artisan('backup:run', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame(1, $this->exports);
        $this->travel(1)->days();
        $this->uploadFails = false;
        $this->artisan('backup:run', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame(2, $this->exports);
        $this->travelBack();
    }

    public function test_process_lock_prevents_overlap_and_existing_state_and_local_archives_are_preserved(): void
    {
        $workspace = new Workspace;
        $workspace->save('state', ['latest' => ['name' => 'old-encrypted-backup']]);
        file_put_contents($this->directory.'/pending.enc', 'old pending archive');
        $lock = $workspace->lock();
        try {
            $this->assertSame('Another backup is running.', app(Runner::class)->run());
            Http::assertNothingSent();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertSame('old-encrypted-backup', $workspace->read('state')['latest']['name']);
        $this->assertFileExists($this->directory.'/pending.enc');
    }

    public function test_workspace_cannot_be_in_public_storage_and_size_budget_fails_safely(): void
    {
        config(['backups.max_workspace_bytes' => 1]);
        $this->artisan('backup:run')->assertFailed();
        $this->assertSame(0, $this->uploadAttempts);
        config(['backups.path' => storage_path('app')]);
        $this->expectException(\RuntimeException::class);
        new Workspace;
    }
}
