<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DriveStorageConnection;
use App\Models\DriveStoredFile;
use App\Models\User;
use App\Notifications\BackupFailedNotification;
use App\Services\Backup\Archive;
use App\Services\Backup\Cipher;
use App\Services\Backup\DatabaseExporter;
use App\Services\Backup\Drive;
use App\Services\Backup\Runner;
use App\Services\Backup\Workspace;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $pair;

    private User $admin;

    private array $files = [];

    private array $bodies = [];

    private array $sessions = [];

    private array $deleted = [];

    private bool $uploadFails = false;

    private bool $corruptReadback = false;

    private bool $badMetadata = false;

    private int $uploadAttempts = 0;

    private int $sequence = 0;

    private int $exports = 0;

    private string $remoteOriginal = '%PDF-1.4 A remote-only uploaded original';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/spms-backup-'.bin2hex(random_bytes(8));
        $this->pair = sodium_crypto_box_keypair();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'backups.enabled' => true,
            'backups.path' => $this->directory, 'backups.public_key' => base64_encode(sodium_crypto_box_publickey($this->pair)),
            'backups.folder_id' => 'backup-folder-12345', 'backups.min_free_bytes' => 0,
            'backups.include' => ['tests/Fixtures/recovery'], 'backups.root_files' => ['composer.lock'],
            'backups.alert_email' => 'backups@example.test', 'mail.default' => 'smtp',
            'logging.channels.backups' => ['driver' => 'single', 'path' => $this->directory.'/test.log'],
            'services.google_drive.client_id' => 'test-client', 'services.google_drive.client_secret' => 'secret-not-for-logs',
            'database.connections.mysql.password' => 'synthetic-config-secret']);
        Storage::fake('local');
        Notification::fake();
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'status' => 'active']);
        DriveStorageConnection::create(['id' => 1, 'account_id' => 'account-test', 'account_email' => 'storage@example.test',
            'connected_by' => $this->admin->id, 'token' => ['access_token' => 'expired-token', 'refresh_token' => 'private-refresh-token', 'expires_at' => 0]]);
        $this->mock(DatabaseExporter::class)->shouldReceive('export')->andReturnUsing(function ($directory) {
            $this->exports++;
            file_put_contents($directory.'/database.sql', 'CREATE TABLE restored (id INTEGER PRIMARY KEY); INSERT INTO restored VALUES (42);');

            return ['driver' => 'mysql', 'file' => 'database.sql'];
        });
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            $id = basename(parse_url($url, PHP_URL_PATH));
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'refreshed-access-token', 'expires_in' => 3600]);
            }
            if (str_contains($url, 'generateIds')) {
                return Http::response(['ids' => ['backup-file-'.++$this->sequence]]);
            }
            if ($id === 'backup-folder-12345') {
                return Http::response(['id' => $id, 'mimeType' => 'application/vnd.google-apps.folder']);
            }
            if ($request->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
                $id = $request['id'];
                $this->uploadAttempts++;
                if ($this->uploadFails) {
                    return Http::response([], 503);
                }
                $this->sessions[$id] = ['id' => $id, 'parents' => $request['parents'], 'appProperties' => $request['appProperties'], 'createdTime' => now()->utc()->toIso8601String()];
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
            if (str_contains($url, 'alt=media')) {
                if ($id === 'source-file-12345') {
                    return Http::response($this->remoteOriginal);
                }

                return Http::response($this->corruptReadback ? 'corrupted-readback' : ($this->bodies[$id] ?? ''));
            }
            if ($request->method() === 'PATCH') {
                $this->files[$id]['appProperties'] = $request['appProperties'];

                return Http::response($this->files[$id]);
            }
            if ($request->method() === 'DELETE') {
                $this->deleted[] = $id;
                unset($this->files[$id]);

                return Http::response('', 204);
            }
            if ($id === 'files') {
                return Http::response(['files' => array_values(array_filter($this->files, fn ($f) => ($f['appProperties']['verified'] ?? '') === 'true'))]);
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
        Log::channel('backups')->getLogger()->close();
        Log::forgetChannel('backups');
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_complete_backup_refreshes_oauth_and_recovers_remote_uploads_code_config_and_sql(): void
    {
        DriveStoredFile::create(['uuid' => (string) Str::uuid(), 'status' => 'uploaded', 'drive_file_id' => 'source-file-12345',
            'account_id' => 'account-test', 'original_name' => 'evidence.pdf', 'mime' => 'application/pdf',
            'local_disk' => 'local', 'local_path' => 'sources/evidence.pdf', 'bytes' => strlen($this->remoteOriginal),
            'sha256' => hash('sha256', $this->remoteOriginal), 'md5' => md5($this->remoteOriginal), 'context' => []]);
        $this->artisan('backup:run')->assertSuccessful();
        $workspace = new Workspace;
        $receipt = $workspace->read('state')['latest'];
        $this->assertTrue($this->files[$receipt['file_id']]['appProperties']['verified'] === 'true');
        $this->assertFileDoesNotExist($this->directory.'/pending.enc');
        $this->assertDirectoryDoesNotExist($this->directory.'/work');
        $this->assertSame('refreshed-access-token', DriveStorageConnection::find(1)->token['access_token']);
        $this->assertStringNotContainsString('private-refresh-token', DB::table('drive_storage_connections')->value('token'));
        file_put_contents($this->directory.'/download.enc', $this->bodies[$receipt['file_id']]);
        Cipher::decrypt($this->directory.'/download.enc', $this->directory.'/restore.zip', sodium_crypto_box_secretkey($this->pair));
        $manifest = Archive::verify($this->directory.'/restore.zip');
        $this->assertArrayHasKey('database.sql', $manifest['files']);
        $this->assertArrayHasKey('application/composer.lock', $manifest['files']);
        $this->assertArrayHasKey('application/tests/Fixtures/recovery/uploads/evidence.txt', $manifest['files']);
        $this->assertArrayNotHasKey('application/tests/Fixtures/recovery/cache/disposable.txt', $manifest['files']);
        $this->assertArrayNotHasKey('application/tests/Fixtures/recovery/backups/previous.backup.enc', $manifest['files']);
        $zip = new \ZipArchive;
        $zip->open($this->directory.'/restore.zip');
        $this->assertSame($this->remoteOriginal, $zip->getFromName('drive-originals/1'));
        $settings = json_decode($zip->getFromName('recovery/effective-config.json'), true);
        $this->assertSame(config('app.key'), $settings['app']['key']);
        $this->assertSame('synthetic-config-secret', $settings['database']['connections']['mysql']['password']);
        // Isolated database restoration: no application/production connection is used.
        $isolated = new \PDO('sqlite::memory:');
        $isolated->exec($zip->getFromName('database.sql'));
        $this->assertSame(42, (int) $isolated->query('SELECT id FROM restored')->fetchColumn());
        $zip->close();
        $this->artisan('backup:verify')->assertSuccessful();
        $log = file_get_contents($this->directory.'/test.log');
        foreach (['private-refresh-token', 'refreshed-access-token', 'synthetic-config-secret', 'secret-not-for-logs', 'upload_id='] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_failed_upload_preserves_encrypted_pending_and_last_success_then_resumes_without_redumping(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $previous = (new Workspace)->read('state')['latest'];
        $this->uploadFails = true;
        $this->artisan('backup:run')->assertFailed();
        $workspace = new Workspace;
        $this->assertSame($previous, $workspace->read('state')['latest']);
        $this->assertFileExists($this->directory.'/pending.enc');
        $this->assertDirectoryDoesNotExist($this->directory.'/work');
        $this->assertSame([], $this->deleted);
        $this->assertSame(1 + config('backups.attempts'), $this->uploadAttempts);
        Notification::assertSentTo($this->admin, BackupFailedNotification::class);
        Notification::assertSentOnDemand(BackupFailedNotification::class);
        $this->uploadFails = false;
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertSame(2, $this->exports);
        $this->assertFileDoesNotExist($this->directory.'/pending.enc');
    }

    public function test_failed_readback_does_not_mark_verified_clean_pending_or_prune(): void
    {
        $this->corruptReadback = true;
        $this->artisan('backup:run')->assertFailed();
        $this->assertFileExists($this->directory.'/pending.enc');
        $this->assertFalse(isset((new Workspace)->read('state')['latest']));
        $this->assertSame('false', array_values($this->files)[0]['appProperties']['verified']);
        $this->assertSame([], $this->deleted);
    }

    public function test_drive_metadata_mismatch_preserves_pending_archive(): void
    {
        $this->badMetadata = true;
        $this->artisan('backup:run')->assertFailed();
        $this->assertFileExists($this->directory.'/pending.enc');
        $this->assertSame([], $this->deleted);
    }

    public function test_retention_removes_only_expired_verified_files_and_keeps_current_backup(): void
    {
        foreach (['expired-file-12345' => 31, 'recent-file-12345' => 29, 'unverified-file-12345' => 40] as $id => $days) {
            $this->files[$id] = ['id' => $id, 'createdTime' => now()->subDays($days)->utc()->toIso8601String(),
                'appProperties' => ['verified' => str_starts_with($id, 'unverified') ? 'false' : 'true', 'backup_set' => 'survey-production']];
        }
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertSame(['expired-file-12345'], $this->deleted);
        $this->assertArrayHasKey('recent-file-12345', $this->files);
        $this->assertArrayHasKey('unverified-file-12345', $this->files);
    }

    public function test_same_kernel_lock_blocks_manual_and_scheduled_runs_without_using_database_cache(): void
    {
        $workspace = new Workspace;
        $lock = $workspace->lock();
        try {
            $this->assertStringContainsString('Another backup', app(Runner::class)->run());
            Http::assertNothingSent();
            $this->assertSame(0, $this->exports);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_schedule_is_karachi_based_with_catchup_throttle_and_no_duplicate_success(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 29)->setTime(21, 0));
        $runner = app(Runner::class);
        $slot = $runner->scheduledSlot();
        $this->assertSame('2026-10-30 02:00 +05:00', $slot->format('Y-m-d H:i P'));
        $this->assertTrue($runner->due([], $slot));
        $this->assertFalse($runner->due(['last_slot' => $slot->toIso8601String()], $slot));
        $this->assertFalse($runner->due(['last_attempt' => now()->subMinutes(30)->toIso8601String()], $slot));
        $this->assertTrue($runner->due(['last_attempt' => now()->subMinutes(61)->toIso8601String()], $slot));
        $this->travelBack();
    }

    public function test_space_limit_fails_safely_and_private_workspace_cannot_be_inside_served_storage(): void
    {
        config(['backups.max_workspace_bytes' => 1]);
        $this->artisan('backup:run')->assertFailed();
        $this->assertDirectoryDoesNotExist($this->directory.'/work');
        $this->assertSame([], $this->deleted);
        config(['backups.path' => base_path('storage/app')]);
        $this->expectException(\RuntimeException::class);
        new Workspace;
    }

    public function test_health_reports_old_snapshot_even_if_its_upload_was_recent_and_throttles_alerts(): void
    {
        $workspace = new Workspace;
        $workspace->save('state', ['latest' => ['created_at' => now()->subDays(3)->toIso8601String(), 'verified_at' => now()->toIso8601String()]]);
        $runner = app(Runner::class);
        $this->assertFalse($runner->health(true)['healthy']);
        $runner->health(true);
        Notification::assertSentToTimes($this->admin, BackupFailedNotification::class, 1);
    }

    public function test_registry_change_during_sql_snapshot_rejects_an_incomplete_file_set(): void
    {
        $this->app->forgetInstance(DatabaseExporter::class);
        $this->mock(DatabaseExporter::class)->shouldReceive('export')->once()->andReturnUsing(function ($directory) {
            file_put_contents($directory.'/database.sql', 'SELECT 1;');
            DriveStoredFile::create(['uuid' => (string) Str::uuid(), 'status' => 'pending', 'original_name' => 'new.pdf',
                'mime' => 'application/pdf', 'local_disk' => 'local', 'local_path' => 'new.pdf', 'bytes' => 10,
                'sha256' => str_repeat('a', 64), 'md5' => str_repeat('b', 32), 'context' => []]);

            return ['driver' => 'mysql'];
        });
        $this->artisan('backup:run')->assertFailed();
        $this->assertFileDoesNotExist($this->directory.'/pending.enc');
        $this->assertDirectoryDoesNotExist($this->directory.'/work');
        $this->assertSame(0, $this->uploadAttempts);
    }

    public function test_old_pending_backup_is_verified_then_a_fresh_snapshot_is_created(): void
    {
        $this->uploadFails = true;
        $this->artisan('backup:run')->assertFailed();
        $workspace = new Workspace;
        $pending = $workspace->read('pending');
        $pending['created_at'] = now()->subDays(2)->toIso8601String();
        $workspace->save('pending', $pending);
        $this->uploadFails = false;
        $this->artisan('backup:run')->assertSuccessful();
        $latest = $workspace->read('state')['latest'];
        $this->assertNotSame($pending['id'], $latest['id']);
        $this->assertSame(2, $this->exports);
        $this->assertCount(2, array_filter($this->files, fn ($f) => ($f['appProperties']['verified'] ?? '') === 'true'));
    }

    public function test_pruning_paginates_and_never_removes_the_newest_verified_file_even_with_a_stale_receipt(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $receipt = (new Workspace)->read('state')['latest'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($receipt) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($id === $receipt['file_id']) {
                return Http::response($this->files[$id]);
            }
            if ($request->method() === 'DELETE') {
                $this->deleted[] = $id;

                return Http::response('', 204);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            if (isset($query['pageToken'])) {
                return Http::response(['files' => [['id' => 'newest-verified-12345', 'createdTime' => now()->subDays(35)->utc()->toIso8601String()]]]);
            }

            return Http::response(['nextPageToken' => 'page-two', 'files' => [['id' => 'expired-verified-12345', 'createdTime' => now()->subDays(40)->utc()->toIso8601String()]]]);
        });
        $this->assertSame(1, app(Drive::class)->prune($receipt, new Workspace));
        $this->assertSame(['expired-verified-12345'], $this->deleted);
    }

    public function test_large_upload_uses_bounded_chunks_and_reuses_identity_after_a_lost_final_response(): void
    {
        $body = str_repeat('x', 8 * 1024 * 1024 + 123);
        $workspace = new Workspace;
        file_put_contents($this->directory.'/test-chunks.enc', $body);
        $receipt = ['file_id' => 'chunk-file-12345', 'folder_id' => 'backup-folder-12345', 'set' => 'survey-production',
            'name' => 'chunks.backup.enc', 'bytes' => strlen($body), 'md5' => md5($body), 'sha256' => hash('sha256', $body)];
        $uploaded = '';
        $complete = false;
        $sessions = 0;
        $parts = 0;
        DriveStorageConnection::find(1)->update(['token' => ['access_token' => 'valid-test-token',
            'refresh_token' => 'private-refresh-token', 'expires_at' => now()->addHour()->timestamp]]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($receipt, &$uploaded, &$complete, &$sessions, &$parts) {
            if ($request->method() === 'POST') {
                $sessions++;
                $this->assertSame($receipt['file_id'], $request['id']);

                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=chunk-session']);
            }
            if ($request->method() === 'PUT') {
                $parts++;
                $this->assertLessThanOrEqual(8 * 1024 * 1024, strlen($request->body()));
                $uploaded .= $request->body();
                if (strlen($uploaded) < $receipt['bytes']) {
                    return Http::response('', 308, ['Range' => 'bytes=0-'.(strlen($uploaded) - 1)]);
                }
                $complete = true;

                return Http::response([], 503); // Server stored the final bytes; client lost confirmation.
            }

            return $complete ? Http::response(['id' => $receipt['file_id'], 'parents' => [$receipt['folder_id']],
                'size' => $receipt['bytes'], 'md5Checksum' => $receipt['md5'], 'appProperties' => ['backup_set' => $receipt['set']]]) : Http::response([], 404);
        });
        $drive = app(Drive::class);
        $drive->useWorkspace($workspace);
        $drive->uploadArchive($receipt, $this->directory.'/test-chunks.enc', $workspace);
        $this->assertSame(1, $sessions);
        $this->assertSame(2, $parts);
        $this->assertSame($receipt['sha256'], hash('sha256', $uploaded));
    }
}
