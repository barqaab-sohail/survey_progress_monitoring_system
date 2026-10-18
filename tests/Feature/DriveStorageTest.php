<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DriveStorageConnection;
use App\Models\DriveStoredFile;
use App\Models\User;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $body = '%PDF-1.4 private source';

    private bool $remote = false;

    private bool $failUpload = false;

    private bool $corrupt = false;

    private int $uploads = 0;

    private int $ids = 0;

    private array $folders = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['drive_storage.enabled' => true, 'drive_storage.folder_id' => 'folder-file-12345',
            'services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client',
            'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/auth/google/callback']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);
        DriveStorageConnection::create(['id' => 1, 'account_id' => 'account-12345', 'account_email' => 'storage@example.test', 'connected_by' => $admin->id,
            'token' => ['access_token' => 'private-access', 'refresh_token' => 'private-refresh', 'expires_at' => now()->addHour()->timestamp]]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'renewed-access', 'expires_in' => 3600, 'refresh_token' => 'new-refresh', 'scope' => 'https://www.googleapis.com/auth/drive.file']);
            }
            if (str_contains($url, '/about')) {
                return Http::response(['user' => ['permissionId' => 'account-12345', 'emailAddress' => 'storage@example.test']]);
            }
            if (str_contains($url, 'generateIds')) {
                return Http::response(['ids' => [++$this->ids === 1 ? 'drive-file-12345' : 'feeder-folder-'.$this->ids]]);
            }
            if (str_contains($url, 'folder-file-12345')) {
                return Http::response(['id' => 'folder-file-12345', 'mimeType' => 'application/vnd.google-apps.folder']);
            }
            if ($request->method() === 'POST' && $request['mimeType'] === 'application/vnd.google-apps.folder') {
                $this->folders[$request['id']] = ['id' => $request['id'], 'mimeType' => $request['mimeType'], 'parents' => $request['parents']];

                return Http::response($this->folders[$request['id']]);
            }
            $id = basename(parse_url($url, PHP_URL_PATH));
            if (str_starts_with($id, 'feeder-folder-')) {
                return isset($this->folders[$id]) ? Http::response($this->folders[$id]) : Http::response([], 404);
            }
            if ($request->method() === 'POST') {
                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=session-private']);
            }
            if ($request->method() === 'PUT') {
                $this->uploads++;
                $this->assertSame($this->body, $request->body());
                $this->remote = true;

                return Http::response([], $this->failUpload ? 503 : 200);
            }
            if (str_contains($url, 'alt=media')) {
                return Http::response($this->corrupt ? 'corrupt data' : $this->body);
            }

            return $this->remote ? Http::response(['id' => 'drive-file-12345', 'size' => strlen($this->body), 'md5Checksum' => md5($this->body)]) : Http::response([], 404);
        });
    }

    private function stage(): DriveStoredFile
    {
        Storage::disk('local')->put('stage/original.pdf', $this->body);

        return app(FileStorage::class)->register('local', 'stage/original.pdf', 'original.pdf', 'application/pdf', ['project_id' => 12, 'feeder_id' => 34, 'batch_id' => 56]);
    }

    public function test_verified_upload_deletes_staging_and_worker_downloads_only_a_temporary_copy(): void
    {
        $file = $this->stage();
        app(FileStorage::class)->transfer($file->id);
        $file->refresh();
        $this->assertSame('uploaded', $file->status);
        $this->assertSame('drive-file-12345', $file->drive_file_id);
        $this->assertSame(12, $file->context['project_id']);
        $this->assertNotNull($file->local_deleted_at);
        Storage::disk('local')->assertMissing($file->local_path);
        $temporary = null;
        app(FileStorage::class)->withPath($file, 'local', $file->local_path, function ($path) use (&$temporary) {
            $temporary = $path;
            $this->assertSame($this->body, file_get_contents($path));
        });
        $this->assertFileDoesNotExist($temporary);
        $this->assertSame(1, $this->uploads);
    }

    public function test_identical_content_with_a_different_name_reuses_one_archive_and_cleans_duplicate_staging(): void
    {
        $first = $this->stage();
        Storage::disk('local')->put('stage/renamed.pdf', $this->body);
        $second = app(FileStorage::class)->register('local', 'stage/renamed.pdf', 'renamed.pdf', 'application/pdf', ['feeder_id' => 99]);
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->duplicate_content);
        $this->assertSame(1, DriveStoredFile::count());
        Storage::disk('local')->assertMissing('stage/renamed.pdf');
        app(FileStorage::class)->transfer($first->id);
        $this->assertSame(1, $this->uploads);
    }

    public function test_different_content_with_same_filename_is_kept_as_a_unique_file_name(): void
    {
        $first = $this->stage();
        $otherBody = '%PDF-1.4 different source';
        Storage::disk('local')->put('stage/other.pdf', $otherBody);
        $second = app(FileStorage::class)->register('local', 'stage/other.pdf', 'original.pdf', 'application/pdf', ['feeder_id' => 35]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->original_name, $second->original_name);
        $this->assertStringContainsString(substr(hash('sha256', $otherBody), 0, 8), $second->original_name);
    }

    public function test_duplicate_report_and_cleanup_repoint_references_before_deleting_only_confirmed_copy(): void
    {
        $keeper = $this->stage();
        $keeper->update(['status' => 'uploaded', 'drive_file_id' => 'drive-file-12345', 'account_id' => 'account-12345', 'uploaded_at' => now()]);
        $duplicate = DriveStoredFile::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'drive_file_id' => 'drive-file-duplicate',
            'original_name' => 'renamed.pdf', 'mime' => 'application/pdf', 'bytes' => strlen($this->body), 'sha256' => hash('sha256', $this->body),
            'md5' => md5($this->body), 'local_disk' => 'local', 'local_path' => 'stage/duplicate.pdf', 'account_id' => 'account-12345',
            'status' => 'uploaded', 'uploaded_at' => now()->addMinute(), 'context' => ['feeder_code' => 'F-2']]);
        \Illuminate\Support\Facades\DB::table('drive_file_hash_claims')->where('sha256', $keeper->sha256)->update(['drive_stored_file_id' => $keeper->id]);
        Storage::disk('local')->put($duplicate->local_path, $this->body);
        $this->assertCount(1, app(\App\Services\DriveStorage\DuplicateFiles::class)->groups());

        $plan = app(\App\Services\DriveStorage\DuplicateFiles::class)->cleanup($keeper->sha256, auth()->user());
        $this->assertSame($keeper->id, $plan['keeper_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.duplicate_cleanup_requested', 'auditable_id' => $keeper->id]);
        $this->assertDatabaseHas('drive_stored_files', ['id' => $duplicate->id]); // no automatic deletion during planning
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('mdb_workflow_sources')->where('drive_stored_file_id', $duplicate->id)->count());
    }

    public function test_interrupted_upload_retains_original_and_retry_uses_same_remote_file(): void
    {
        $file = $this->stage();
        $this->failUpload = true;
        try {
            app(FileStorage::class)->transfer($file->id);
            $this->fail('Expected interrupted transfer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('session-private', $exception->getMessage());
        }
        $this->assertSame('failed', $file->fresh()->status);
        Storage::disk('local')->assertExists($file->local_path);
        $id = $file->fresh()->drive_file_id;
        $this->failUpload = false;
        app(FileStorage::class)->transfer($file->id);
        $this->assertSame($id, $file->fresh()->drive_file_id);
        $this->assertSame(1, $this->uploads);
        Storage::disk('local')->assertMissing($file->local_path);
    }

    public function test_checksum_failure_never_discards_the_only_original(): void
    {
        $file = $this->stage();
        $this->remote = true;
        $file->update(['drive_file_id' => 'drive-file-12345', 'account_id' => 'account-12345', 'md5' => str_repeat('0', 32)]);
        try {
            app(FileStorage::class)->transfer($file->id);
            $this->fail('Expected checksum rejection.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $file->fresh()->status);
        Storage::disk('local')->assertExists($file->local_path);
    }

    public function test_failed_download_removes_temporary_data_and_keeps_remote_reference(): void
    {
        $file = $this->stage();
        app(FileStorage::class)->transfer($file->id);
        $this->corrupt = true;
        try {
            app(FileStorage::class)->withPath($file, 'local', $file->local_path, fn () => null);
            $this->fail('Expected integrity rejection.');
        } catch (\RuntimeException) {
        }
        $this->assertSame([], Storage::disk('local')->allFiles('drive-storage/work'));
        $this->assertSame('uploaded', $file->fresh()->status);
    }

    public function test_pending_file_remains_viewable_when_google_is_unavailable(): void
    {
        $file = $this->stage();
        DriveStorageConnection::query()->delete();
        app(FileStorage::class)->withPath($file, 'local', $file->local_path, fn ($path) => $this->assertSame($this->body, file_get_contents($path)));
        try {
            app(FileStorage::class)->transfer($file->id);
            $this->fail('Expected missing account rejection.');
        } catch (\RuntimeException) {
        }
        Storage::disk('local')->assertExists($file->local_path);
    }

    public function test_pending_and_archived_pdf_responses_send_the_original_bytes(): void
    {
        $file = $this->stage();
        foreach (['pending', 'uploaded'] as $status) {
            if ($status === 'uploaded') {
                app(FileStorage::class)->transfer($file->id);
                $file->refresh();
            }
            $response = app(FileStorage::class)->response($file, 'local', $file->local_path, 'original.pdf', 'application/pdf');
            $snapshot = $response->getFile()->getPathname();
            $response->prepare(Request::create('/source.pdf'));
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
            $this->assertSame((string) strlen($this->body), $response->headers->get('Content-Length'));
            ob_start();
            try {
                $response->sendContent();
                $body = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame($this->body, $body);
            $this->assertFileDoesNotExist($snapshot);
        }
    }

    public function test_expired_storage_token_is_refreshed_encrypted_and_hidden(): void
    {
        $connection = DriveStorageConnection::findOrFail(1);
        $token = $connection->token;
        $token['expires_at'] = 0;
        $connection->update(['token' => $token]);
        $file = $this->stage();
        app(FileStorage::class)->transfer($file->id);
        $this->assertSame('renewed-access', $connection->fresh()->token['access_token']);
        $this->assertSame('new-refresh', $connection->fresh()->token['refresh_token']);
        $this->assertArrayNotHasKey('token', $connection->toArray());
        $this->assertStringNotContainsString('private-refresh', DB::table('drive_storage_connections')->value('token'));
    }

    public function test_only_admin_can_connect_storage_and_personal_disconnect_keeps_it(): void
    {
        $this->delete('/google-drive')->assertRedirect();
        $this->assertDatabaseCount('drive_storage_connections', 1);
        $this->actingAs(User::factory()->create())->post('/auth/google', ['application_storage' => 1])->assertForbidden();
    }

    public function test_admin_oauth_keeps_storage_credentials_separate_from_personal_imports(): void
    {
        $response = $this->post('/auth/google', ['application_storage' => 1]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'code']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('renewed-access', DriveStorageConnection::find(1)->token['access_token']);
        $this->assertNull(auth()->user()->fresh()->google_drive_token);
    }

    public function test_recovery_cleans_confirmed_files_without_reuploading(): void
    {
        $file = $this->stage();
        $file->update(['status' => 'uploaded', 'drive_file_id' => 'drive-file-12345', 'account_id' => 'account-12345']);
        $this->artisan('drive-storage:retry')->assertSuccessful();
        Storage::disk('local')->assertMissing($file->local_path);
        $this->assertNotNull($file->fresh()->local_deleted_at);
        Http::assertNothingSent();
    }
}
