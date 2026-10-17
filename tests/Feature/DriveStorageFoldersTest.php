<?php

namespace Tests\Feature;

use App\Models\DriveStorageConnection;
use App\Models\DriveStorageFolder;
use App\Models\DriveStoredFile;
use App\Models\User;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveStorageFoldersTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];

    private array $sessions = [];

    private int $ids = 0;

    private int $folderCreates = 0;

    private int $uploads = 0;

    private int $moves = 0;

    private bool $interruptFolder = false;

    private bool $failMove = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['drive_storage.enabled' => true, 'drive_storage.folder_id' => 'root-folder', 'drive_storage.timeout' => 10]);
        DriveStorageConnection::create(['id' => 1, 'account_id' => 'account-123', 'account_email' => 'storage@example.test',
            'connected_by' => User::factory()->create()->id,
            'token' => ['access_token' => 'private-token', 'expires_at' => now()->addHour()->timestamp]]);
        $this->remote['root-folder'] = ['id' => 'root-folder', 'mimeType' => 'application/vnd.google-apps.folder'];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            $id = basename(parse_url($url, PHP_URL_PATH));
            if ($id === 'generateIds') {
                return Http::response(['ids' => ['reserved-'.(++$this->ids)]]);
            }
            if ($request->method() === 'GET') {
                return isset($this->remote[$id]) ? Http::response($this->remote[$id]) : Http::response([], 404);
            }
            if ($request->method() === 'POST' && $request['mimeType'] === 'application/vnd.google-apps.folder') {
                $this->folderCreates++;
                $this->assertArrayNotHasKey($request['id'], $this->remote, 'A retry must reuse the folder created before an interrupted response.');
                $this->remote[$request['id']] = $request->data();
                if ($this->interruptFolder) {
                    $this->interruptFolder = false;

                    return Http::response([], 503);
                }

                return Http::response($request->data());
            }
            if ($request->method() === 'POST' && str_contains($url, '/upload/')) {
                $this->sessions[$request['id']] = $request->data();

                return Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id='.$request['id']]);
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if ($request->method() === 'PUT') {
                $this->uploads++;
                $fileId = $query['upload_id'];
                $body = $request->body();
                $this->remote[$fileId] = $this->sessions[$fileId] + ['size' => strlen($body), 'md5Checksum' => md5($body)];

                return Http::response(['id' => $fileId]);
            }
            if ($request->method() === 'PATCH') {
                $this->assertSame('{}', $request->body());
                if ($this->failMove) {
                    return Http::response([], 503);
                }
                $this->moves++;
                $this->assertContains($query['removeParents'], $this->remote[$id]['parents']);
                $this->remote[$id]['parents'] = [$query['addParents']];

                return Http::response(['id' => $id]);
            }
            $this->fail('Unexpected Google request: '.$request->method().' '.$id);
        });
    }

    private function stage(string $name, array $context): DriveStoredFile
    {
        $path = 'stage/'.uniqid().'-'.$name;
        Storage::disk('local')->put($path, 'original-'.$name);

        return app(FileStorage::class)->register('local', $path, $name,
            str_ends_with($name, '.pdf') ? 'application/pdf' : 'application/gpx+xml', $context);
    }

    private function feeder(int $id = 407): array
    {
        return ['feeder_id' => $id, 'feeder_code' => (string) $id, 'feeder_name' => $id === 407 ? 'Nawan Shehra' : 'Other Feeder'];
    }

    public function test_pdf_and_gpx_reuse_their_feeder_folder_and_other_feeders_are_isolated(): void
    {
        $pdf = $this->stage('A_10102026_05.pdf', $this->feeder());
        $gpx = $this->stage('A_10102026_05.gpx', $this->feeder());
        $other = $this->stage('B.pdf', $this->feeder(408));
        foreach ([$pdf, $gpx, $other] as $file) {
            app(FileStorage::class)->transfer($file->id);
            $file->refresh();
        }
        $folder = DriveStorageFolder::where('feeder_id', 407)->firstOrFail();
        $this->assertSame('407-Nawan Shehra', $folder->name);
        $this->assertSame(['root-folder'], $this->remote[$folder->drive_folder_id]['parents']);
        foreach ([$pdf, $gpx] as $file) {
            $this->assertSame([$folder->drive_folder_id], $this->remote[$file->drive_file_id]['parents']);
            $this->assertSame($file->original_name, $this->remote[$file->drive_file_id]['name']);
        }
        $this->assertNotSame($this->remote[$pdf->drive_file_id]['parents'], $this->remote[$other->drive_file_id]['parents']);
        $this->assertSame(2, $this->folderCreates);
        $this->assertSame(3, $this->uploads);
    }

    public function test_interrupted_folder_creation_reuses_reserved_id_and_retains_original(): void
    {
        $file = $this->stage('A.pdf', $this->feeder());
        $this->interruptFolder = true;
        try {
            app(FileStorage::class)->transfer($file->id);
            $this->fail('Expected an interrupted folder response.');
        } catch (\RuntimeException) {
        }
        Storage::disk('local')->assertExists($file->local_path);
        $folderId = DriveStorageFolder::firstOrFail()->drive_folder_id;
        app(FileStorage::class)->transfer($file->id);
        $file->refresh();
        $this->assertSame([$folderId], $this->remote[$file->drive_file_id]['parents']);
        $this->assertSame('uploaded', $file->status);
        $this->assertSame(1, $this->folderCreates);
        $this->assertSame(1, $this->uploads);
    }

    public function test_empty_root_setting_creates_and_remembers_root_then_creates_feeder_child(): void
    {
        config(['drive_storage.folder_id' => null, 'drive_storage.folder_name' => 'Survey Source Files']);
        $file = $this->stage('A.pdf', $this->feeder());
        app(FileStorage::class)->transfer($file->id);
        $root = DriveStorageConnection::findOrFail(1)->folder_id;
        $folder = DriveStorageFolder::firstOrFail();
        $this->assertSame('Survey Source Files', $this->remote[$root]['name']);
        $this->assertSame($root, $folder->root_folder_id);
        $this->assertSame([$root], $this->remote[$folder->drive_folder_id]['parents']);
        $this->assertSame([$folder->drive_folder_id], $this->remote[$file->fresh()->drive_file_id]['parents']);
        $next = $this->stage('A.gpx', $this->feeder());
        app(FileStorage::class)->transfer($next->id);
        $this->assertSame(2, $this->folderCreates, 'Only the root and one feeder folder should be created.');
    }

    public function test_trashed_feeder_folder_blocks_upload_and_changed_root_gets_separate_mapping(): void
    {
        $file = $this->stage('first.pdf', $this->feeder());
        app(FileStorage::class)->transfer($file->id);
        $folder = DriveStorageFolder::firstOrFail();
        $this->remote[$folder->drive_folder_id]['trashed'] = true;
        $next = $this->stage('second.pdf', $this->feeder());
        try {
            app(FileStorage::class)->transfer($next->id);
            $this->fail('Expected unavailable folder rejection.');
        } catch (\RuntimeException) {
        }
        Storage::disk('local')->assertExists($next->local_path);
        $this->assertSame(1, $this->uploads);
        config(['drive_storage.folder_id' => 'new-root-folder']);
        $this->remote['new-root-folder'] = ['id' => 'new-root-folder', 'mimeType' => 'application/vnd.google-apps.folder'];
        app(FileStorage::class)->transfer($next->id);
        $newFolder = DriveStorageFolder::where('root_folder_id', 'new-root-folder')->firstOrFail();
        $this->assertNotSame($folder->drive_folder_id, $newFolder->drive_folder_id);
        $this->assertSame([$newFolder->drive_folder_id], $this->remote[$next->fresh()->drive_file_id]['parents']);
        $this->assertDatabaseCount('drive_storage_folders', 2);
    }

    public function test_organizing_existing_files_preserves_ids_checksums_and_does_not_reupload(): void
    {
        $file = $this->stage('old.pdf', []);
        app(FileStorage::class)->transfer($file->id);
        $file->refresh();
        $original = $file->only(['drive_file_id', 'sha256', 'md5', 'original_name', 'uploaded_at', 'status']);
        $file->update(['context' => $this->feeder()]);
        $this->artisan('drive-storage:organize')->expectsOutputToContain('1 file(s) moved')->assertSuccessful();
        $folder = DriveStorageFolder::firstOrFail();
        $this->assertSame([$folder->drive_folder_id], $this->remote[$file->drive_file_id]['parents']);
        $this->assertEquals($original, $file->fresh()->only(array_keys($original)));
        $this->artisan('drive-storage:organize')->expectsOutputToContain('0 file(s) moved')->assertSuccessful();
        $this->assertSame(1, $this->moves);
        $this->assertSame(1, $this->uploads);
        Storage::disk('local')->assertMissing($file->local_path);
    }

    public function test_failed_move_is_retryable_without_changing_upload_status_or_file_identity(): void
    {
        $file = $this->stage('old.gpx', []);
        app(FileStorage::class)->transfer($file->id);
        $file->refresh()->update(['context' => $this->feeder()]);
        $id = $file->drive_file_id;
        $this->failMove = true;
        $this->artisan('drive-storage:organize', ['--id' => $file->id])->assertFailed();
        $this->assertSame(['root-folder'], $this->remote[$id]['parents']);
        $this->assertSame('uploaded', $file->fresh()->status);
        $this->failMove = false;
        $this->artisan('drive-storage:organize', ['--id' => $file->id])->assertSuccessful();
        $this->assertSame($id, $file->fresh()->drive_file_id);
        $this->assertSame(1, $this->folderCreates);
    }

    public function test_organizer_rejects_changed_account_or_corrupt_remote_and_leaves_unknown_files_at_root(): void
    {
        $unknown = $this->stage('unassigned.pdf', []);
        app(FileStorage::class)->transfer($unknown->id);
        $this->artisan('drive-storage:organize')->expectsOutputToContain('1 already organized or without a feeder')->assertSuccessful();
        $this->assertSame(0, $this->folderCreates);
        $unknown->refresh()->update(['context' => $this->feeder()]);
        $this->remote[$unknown->drive_file_id]['md5Checksum'] = str_repeat('0', 32);
        $this->artisan('drive-storage:organize')->assertFailed();
        $this->assertSame(0, $this->folderCreates);
        DriveStorageConnection::find(1)->update(['account_id' => 'different-account']);
        Http::fake(fn () => $this->fail('Account mismatch must fail before any Google request.'));
        $this->artisan('drive-storage:organize')->assertFailed();
        $this->assertSame(0, $this->moves);
    }
}
