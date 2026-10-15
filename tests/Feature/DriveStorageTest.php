<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DriveStorageConnection;
use App\Models\DriveStoredFile;
use App\Models\User;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                return Http::response(['ids' => ['drive-file-12345']]);
            }
            if (str_contains($url, 'folder-file-12345')) {
                return Http::response(['id' => 'folder-file-12345', 'mimeType' => 'application/vnd.google-apps.folder']);
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
