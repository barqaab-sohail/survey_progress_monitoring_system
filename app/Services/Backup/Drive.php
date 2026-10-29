<?php

namespace App\Services\Backup;

use App\Models\DriveStorageConnection;
use App\Services\DriveStorage\DriveClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/** Uses the application's existing encrypted OAuth connection; no second token store. */
class Drive extends DriveClient
{
    private ?Workspace $workspace = null;

    public function useWorkspace(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function retry(callable $action, ?callable $tick = null): mixed
    {
        $attempts = max(1, min(5, config('backups.attempts')));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $tick && $tick();

                return $action();
            } catch (\Throwable) {
                if ($attempt + 1 === $attempts) {
                    throw new Failure('Backup Drive operation failed after bounded retries; check OAuth, quota and network.');
                }
                usleep((2 ** $attempt) * 250000);
            }
        }
    }

    private function request(string $method, string $url, array $options = [], array $accepted = []): Response
    {
        $this->workspace?->tick();
        $timeout = min(config('backups.http_timeout'), $this->workspace?->remainingSeconds() ?? config('backups.http_timeout'));
        $response = $this->client()->connectTimeout(min(15, $timeout))->timeout($timeout)->send($method, $url, $options);
        if ($response->status() === 401) {
            Cache::lock('drive-storage:oauth', 60)->block(10, function () {
                $connection = DriveStorageConnection::findOrFail(1);
                $token = $connection->token;
                $token['expires_at'] = 0;
                $connection->update(['token' => $token]);
            });
            throw new Failure('Drive authorization must refresh.');
        }
        if (! $response->successful() && ! in_array($response->status(), $accepted, true)) {
            throw new Failure('Drive request failed.'); // Never include API bodies, tokens or session URLs.
        }

        return $response;
    }

    public function metadata(string $id): ?array
    {
        $this->validId($id);
        $response = $this->retry(fn () => $this->request('GET', self::API.'/'.$id, ['query' => [
            'fields' => 'id,name,size,md5Checksum,trashed,mimeType,parents', 'supportsAllDrives' => 'true',
        ]], [404]));

        return $response->status() === 404 ? null : $response->json();
    }

    public function createBackupFolder(): string
    {
        $id = $this->retry(fn () => $this->generateId());
        $this->retry(function () use ($id) {
            if ($this->metadata($id)) {
                return;
            }
            $this->request('POST', self::API, ['json' => ['id' => $id, 'name' => 'Application backups',
                'mimeType' => 'application/vnd.google-apps.folder']]);
        });

        return $id;
    }

    public function assertFolder(string $id): void
    {
        $info = $this->metadata($id);
        if (! $info || ($info['trashed'] ?? false) || ($info['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            throw new Failure('The dedicated backup folder is unavailable. Run backup:folder and configure its ID.');
        }
    }

    public function uploadArchive(array $receipt, string $path, Workspace $workspace): void
    {
        $this->retry(function () use ($receipt, $path, $workspace) {
            if ($existing = $this->metadata($receipt['file_id'])) {
                $this->assertMetadata($receipt, $existing);

                return;
            }
            $response = $this->request('POST', 'https://www.googleapis.com/upload/drive/v3/files', [
                'query' => ['uploadType' => 'resumable', 'supportsAllDrives' => 'true'],
                'headers' => ['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $receipt['bytes']],
                'json' => ['id' => $receipt['file_id'], 'name' => $receipt['name'], 'mimeType' => 'application/zip', 'parents' => [$receipt['folder_id']]],
            ]);
            $location = $response->header('Location');
            if (! is_string($location) || ! str_starts_with($location, 'https://www.googleapis.com/upload/drive/')) {
                throw new Failure('Invalid Drive resumable upload location.');
            }
            $stream = fopen($path, 'rb');
            try {
                $offset = 0;
                while ($offset < $receipt['bytes']) {
                    $workspace->tick();
                    $chunk = fread($stream, 8 * 1024 * 1024); // Multiple of Google's 256 KiB requirement.
                    if ($chunk === false || $chunk === '') {
                        throw new Failure('Backup ZIP read failed.');
                    }
                    $end = $offset + strlen($chunk) - 1;
                    $part = $this->request('PUT', $location, ['body' => $chunk, 'headers' => [
                        'Content-Type' => 'application/zip', 'Content-Length' => (string) strlen($chunk),
                        'Content-Range' => 'bytes '.$offset.'-'.$end.'/'.$receipt['bytes'],
                    ]], [308]);
                    if ($part->status() === 308 && $part->header('Range') !== 'bytes=0-'.$end) {
                        throw new Failure('Drive acknowledged an unexpected upload offset.');
                    }
                    $offset = $end + 1;
                    if ($part->successful() && $offset !== $receipt['bytes']) {
                        throw new Failure('Drive completed an incomplete upload.');
                    }
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $this->assertMetadata($receipt, $this->metadata($receipt['file_id']) ?? []);
        }, fn () => $workspace->tick());
    }

    public function assertMetadata(array $receipt, array $info): void
    {
        if (($info['trashed'] ?? false) || ($info['id'] ?? '') !== $receipt['file_id']
            || (int) ($info['size'] ?? -1) !== $receipt['bytes'] || ! hash_equals($receipt['md5'], $info['md5Checksum'] ?? '')
            || ! in_array($receipt['folder_id'], $info['parents'] ?? [], true)) {
            throw new Failure('Uploaded backup metadata or MD5 verification failed.');
        }
    }

    private function validId(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{5,200}$/D', $id)) {
            throw new Failure('Invalid backup Drive identity.');
        }
    }
}
