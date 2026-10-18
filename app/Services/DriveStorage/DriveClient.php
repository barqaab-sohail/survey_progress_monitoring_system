<?php

namespace App\Services\DriveStorage;

use App\Models\DriveStorageConnection;
use App\Models\DriveStorageFolder;
use App\Models\DriveStoredFile;
use App\Models\Feeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DriveClient
{
    public const API = 'https://www.googleapis.com/drive/v3/files';

    private function client()
    {
        $token = Cache::lock('drive-storage:oauth', 60)->block(10, function () {
            $connection = DriveStorageConnection::find(1);
            if (! $connection) {
                throw new RuntimeException('Connect the application storage account in Google Drive settings.');
            }
            $token = $connection->token;
            if (($token['expires_at'] ?? 0) <= now()->addMinute()->timestamp) {
                if (empty($token['refresh_token'])) {
                    throw new RuntimeException('Reconnect the application storage account.');
                }
                try {
                    $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                        'client_id' => config('services.google_drive.client_id'), 'client_secret' => config('services.google_drive.client_secret'),
                        'grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'],
                    ]);
                } catch (\Throwable) {
                    throw new RuntimeException('Google authorization is temporarily unavailable.');
                }
                if (! $response->successful() || ! $response->json('access_token')) {
                    throw new RuntimeException('Reconnect the application storage account.');
                }
                $token['access_token'] = $response->json('access_token');
                if ($response->json('refresh_token')) {
                    $token['refresh_token'] = $response->json('refresh_token');
                }
                $token['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->timestamp;
                $connection->update(['token' => $token]);
            }

            return $token['access_token'];
        });

        return Http::withToken($token)->timeout(config('drive_storage.timeout', 180))->withOptions(['allow_redirects' => false]);
    }

    public function generateId(): string
    {
        $response = $this->client()->get(self::API.'/generateIds', ['count' => 1, 'space' => 'drive', 'type' => 'files']);
        if (! $response->successful() || ! preg_match('/^[A-Za-z0-9_-]{5,200}$/D', $response->json('ids.0', ''))) {
            throw new RuntimeException('Drive could not reserve a file identity.');
        }

        return $response->json('ids.0');
    }

    public function info(string $id): ?array
    {
        $this->assertId($id);
        $response = $this->client()->get(self::API.'/'.$id, ['fields' => 'id,size,md5Checksum,trashed,mimeType,parents', 'supportsAllDrives' => 'true']);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Drive metadata could not be verified. Check account access and retry.');
        }

        return $response->json();
    }

    private function folder(): string
    {
        return Cache::lock('drive-storage:folder', 60)->block(10, function () {
            $connection = DriveStorageConnection::findOrFail(1);
            if ($configured = config('drive_storage.folder_id')) {
                $this->assertId($configured);
                $info = $this->info($configured);
                if (! $info || ($info['trashed'] ?? false) || ($info['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                    throw new RuntimeException('The configured Drive storage folder is unavailable.');
                }

                return $configured;
            }
            if (! $connection->folder_id) {
                $connection->update(['folder_id' => $this->generateId()]);
            }
            if (! $this->info($connection->folder_id)) {
                $response = $this->client()->post(self::API, ['id' => $connection->folder_id, 'name' => config('drive_storage.folder_name'),
                    'mimeType' => 'application/vnd.google-apps.folder']);
                if (! $response->successful()) {
                    throw new RuntimeException('Drive storage folder could not be created.');
                }
            }

            return $connection->folder_id;
        });
    }

    public function upload(DriveStoredFile $file, string $path): void
    {
        $existing = $this->info($file->drive_file_id);
        if ($existing) {
            $this->verify($file, $existing);

            return;
        }
        $response = $this->client()->withHeaders(['X-Upload-Content-Type' => $file->mime, 'X-Upload-Content-Length' => (string) $file->bytes])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
                'id' => $file->drive_file_id, 'name' => $file->original_name, 'mimeType' => $file->mime, 'parents' => [$this->destinationFolder($file)],
                'appProperties' => ['storage_uuid' => $file->uuid, 'project_id' => (string) ($file->context['project_id'] ?? ''),
                    'feeder_id' => (string) ($file->context['feeder_id'] ?? ''), 'batch_id' => (string) ($file->context['batch_id'] ?? '')],
            ]);
        $location = $response->header('Location');
        if (! $response->successful() || ! is_string($location) || ! str_starts_with($location, 'https://www.googleapis.com/upload/drive/')) {
            throw new RuntimeException('Drive could not start the file transfer.');
        }
        $stream = fopen($path, 'rb');
        try {
            $upload = $this->client()->withHeaders(['Content-Length' => (string) $file->bytes])->withBody($stream, $file->mime)->put($location);
            if (! $upload->successful()) {
                throw new RuntimeException('Drive transfer was interrupted. The staged file is retained for retry.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->verify($file, $this->info($file->drive_file_id) ?? []);
    }

    private function destinationFolder(DriveStoredFile $file, ?string $root = null): string
    {
        $root ??= $this->folder();
        $feederId = (int) ($file->context['feeder_id'] ?? 0);
        if ($feederId <= 0) {
            return $root;
        }
        $connection = DriveStorageConnection::findOrFail(1);
        $key = hash('sha256', $connection->account_id.'|'.$root.'|'.$feederId);

        return Cache::lock('drive-storage:folder:'.$key, 60)->block(10, function () use ($connection, $root, $feederId, $file) {
            $folder = DriveStorageFolder::where('account_id', $connection->account_id)
                ->where('root_folder_id', $root)->where('feeder_id', $feederId)->first();
            if (! $folder) {
                $feeder = Feeder::find($feederId);
                $code = trim((string) ($feeder?->feeder_code ?: ($file->context['feeder_code'] ?? '')));
                $name = trim((string) ($feeder?->feeder_name ?: ($file->context['feeder_name'] ?? '')));
                $label = $code !== '' ? $code : 'Feeder-'.$feederId;
                if ($name !== '') {
                    $label .= '-'.$name;
                }
                $label = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '-', $label)), 0, 200);
                // Persist the reserved ID before calling Google so retries cannot create duplicate folders.
                $folder = DriveStorageFolder::create(['account_id' => $connection->account_id, 'root_folder_id' => $root,
                    'feeder_id' => $feederId, 'drive_folder_id' => $this->generateId(), 'name' => $label]);
            }
            $info = $this->info($folder->drive_folder_id);
            if (! $info) {
                $response = $this->client()->post(self::API.'?supportsAllDrives=true', [
                    'id' => $folder->drive_folder_id, 'name' => $folder->name,
                    'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$root],
                    'appProperties' => ['feeder_id' => (string) $feederId],
                ]);
                if (! $response->successful()) {
                    throw new RuntimeException('The feeder Drive folder could not be created.');
                }
                $info = $this->info($folder->drive_folder_id);
            }
            if (! $info || ($info['trashed'] ?? false) || ($info['mimeType'] ?? '') !== 'application/vnd.google-apps.folder'
                || ! in_array($root, $info['parents'] ?? [], true)) {
                throw new RuntimeException('The feeder Drive folder is unavailable or outside the storage root.');
            }

            return $folder->drive_folder_id;
        });
    }

    public function organize(DriveStoredFile $file): bool
    {
        return Cache::lock('drive-storage:file:'.$file->id, 600)->block(10, function () use ($file) {
            $file->refresh();
            if ($file->status !== 'uploaded' || (int) ($file->context['feeder_id'] ?? 0) <= 0) {
                return false;
            }
            if ($file->account_id !== DriveStorageConnection::find(1)?->account_id) {
                throw new RuntimeException('Reconnect the original Drive storage account before organizing files.');
            }
            $info = $this->info($file->drive_file_id) ?? [];
            $this->verify($file, $info);
            $root = $this->folder();
            $destination = $this->destinationFolder($file, $root);
            $parents = $info['parents'] ?? [];
            if (in_array($destination, $parents, true)) {
                return false;
            }
            if (! in_array($root, $parents, true)) {
                throw new RuntimeException('The file is outside the current storage root; it was left unchanged.');
            }
            $query = http_build_query(['addParents' => $destination, 'removeParents' => $root,
                'supportsAllDrives' => 'true', 'fields' => 'id']);
            $response = $this->client()->withBody('{}', 'application/json')->patch(self::API.'/'.$file->drive_file_id.'?'.$query);
            if (! $response->successful()) {
                throw new RuntimeException('Drive could not move the file into its feeder folder.');
            }
            $updated = $this->info($file->drive_file_id) ?? [];
            $this->verify($file, $updated);
            if (! in_array($destination, $updated['parents'] ?? [], true)) {
                throw new RuntimeException('Drive has not confirmed the feeder folder move; retry safely.');
            }

            return true;
        });
    }

    public function deleteVerified(DriveStoredFile $file): void
    {
        if (! $file->drive_file_id || $file->account_id !== DriveStorageConnection::find(1)?->account_id) {
            throw new RuntimeException('Reconnect the original storage account before removing a duplicate.');
        }
        $info = $this->info($file->drive_file_id);
        if ($info === null) {
            return; // Idempotent recovery if Drive deletion succeeded before the database update.
        }
        $this->verify($file, $info);
        $response = $this->client()->delete(self::API.'/'.$file->drive_file_id, ['supportsAllDrives' => 'true']);
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Google Drive did not confirm deletion of duplicate '.$file->original_name.'.');
        }
        if ($this->info($file->drive_file_id) !== null) {
            throw new RuntimeException('Google Drive still reports the duplicate file. Please retry cleanup.');
        }
    }

    public function verifyRemote(DriveStoredFile $file): void
    {
        if (! $file->drive_file_id || $file->account_id !== DriveStorageConnection::find(1)?->account_id) {
            throw new RuntimeException('Reconnect the original storage account before verifying duplicates.');
        }
        $info = $this->info($file->drive_file_id);
        if ($info === null) throw new RuntimeException('A reported file is no longer available in Drive. Refresh the report.');
        $this->verify($file, $info);
    }

    private function verify(DriveStoredFile $file, array $info): void
    {
        if (($info['trashed'] ?? false) || (string) ($info['id'] ?? '') !== $file->drive_file_id
            || (int) ($info['size'] ?? -1) !== $file->bytes || ! hash_equals($file->md5, $info['md5Checksum'] ?? '')) {
            throw new RuntimeException('Drive checksum verification failed. The staged file is retained.');
        }
    }

    public function download(DriveStoredFile $file, string $path): void
    {
        $this->assertId($file->drive_file_id);
        $response = $this->client()->withOptions(['sink' => $path, 'progress' => function ($total, $downloaded) use ($file) {
            if ($downloaded > $file->bytes) {
                throw new RuntimeException('Drive download exceeded its recorded size.');
            }
        }])->get(self::API.'/'.$file->drive_file_id, ['alt' => 'media', 'supportsAllDrives' => 'true']);
        if (! $response->successful()) {
            throw new RuntimeException('Drive file is temporarily unavailable. Try again later.');
        }
    }

    private function assertId(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{5,200}$/D', $id)) {
            throw new RuntimeException('Invalid stored Drive identity.');
        }
    }
}
