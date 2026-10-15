<?php

namespace App\Services\DriveStorage;

use App\Models\DriveStorageConnection;
use App\Models\DriveStoredFile;
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
        $response = $this->client()->get(self::API.'/'.$id, ['fields' => 'id,size,md5Checksum,trashed,mimeType', 'supportsAllDrives' => 'true']);
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
                'id' => $file->drive_file_id, 'name' => $file->original_name, 'mimeType' => $file->mime, 'parents' => [$this->folder()],
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
