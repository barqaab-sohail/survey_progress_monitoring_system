<?php

namespace App\Services\SurveyProgress;

use App\Models\SurveyProgress\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DriveClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    private function token(): string
    {
        return Cache::lock('survey-progress:oauth', 60)->block(10, function () {
            $connection = Connection::find(1);
            if (! $connection) {
                throw new RuntimeException('Connect the survey progress read-only Google Drive account first.');
            }
            $token = $connection->token;
            if (! in_array(self::SCOPE, explode(' ', $token['scope'] ?? ''), true)) {
                throw new RuntimeException('Survey progress requires its separate read-only Drive authorization.');
            }
            if (($token['expires_at'] ?? 0) <= now()->addMinute()->timestamp) {
                if (empty($token['refresh_token'])) {
                    throw new RuntimeException('Reconnect Google Drive to enable unattended daily synchronization.');
                }
                if (! config('survey_progress.client_id') || ! config('survey_progress.client_secret')) {
                    throw new RuntimeException('Survey progress OAuth client credentials are missing. Check the production configuration cache.');
                }
                $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('survey_progress.client_id'), 'client_secret' => config('survey_progress.client_secret'),
                    'refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token',
                ]);
                if (! $response->successful() || ! $response->json('access_token')) {
                    throw new RuntimeException('Google authorization could not be refreshed. Reconnect the survey progress account.');
                }
                $token['access_token'] = $response->json('access_token');
                $token['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->timestamp;
                $connection->update(['token' => $token]);
            }

            return $token['access_token'];
        });
    }

    public function children(string $folder): array
    {
        $this->assertId($folder);
        // A listing query can return an empty array for an inaccessible folder. Verify access
        // first so that lost permissions never replace a previous successful count with zero.
        $this->folder($folder);
        $files = [];
        $page = null;
        do {
            $response = $this->get('files', array_filter([
                'q' => "'{$folder}' in parents and trashed = false", 'pageSize' => 1000,
                'fields' => 'nextPageToken,incompleteSearch,files(id,name,mimeType,modifiedTime,md5Checksum,size,version)',
                'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'pageToken' => $page,
            ], fn ($v) => $v !== null));
            if (! $response->successful() || ! is_array($response->json('files')) || $response->json('incompleteSearch')) {
                throw new RuntimeException('Drive folder listing failed or is incomplete; previous progress is retained.');
            }
            array_push($files, ...$response->json('files'));
            $page = $response->json('nextPageToken');
            if (count($files) > 20000) {
                throw new RuntimeException('Folder inventory exceeds the configured safe scan size.');
            }
        } while ($page);

        return $files;
    }

    public function folder(string $id): array
    {
        $this->assertId($id);
        $response = $this->get('files/'.$id, ['supportsAllDrives' => 'true', 'fields' => 'id,name,mimeType,trashed,capabilities(canListChildren)']);
        $folder = $response->json();
        if (($folder['mimeType'] ?? null) !== 'application/vnd.google-apps.folder' || ! empty($folder['trashed']) || ($folder['capabilities']['canListChildren'] ?? true) === false) {
            throw new RuntimeException('The configured Drive folder cannot be listed. Check its folder ID and share it with the connected Google account.');
        }

        return $folder;
    }

    private function get(string $path, array $query): Response
    {
        try {
            $response = Http::withToken($this->token())->connectTimeout(15)->timeout(60)->retry(3, 500, throw: false)
                ->get('https://www.googleapis.com/drive/v3/'.$path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('Google Drive could not be reached. Check outbound HTTPS, server CA certificates and network timeouts.');
        }
        if (! $response->successful()) {
            $reason = $response->json('error.errors.0.reason');
            $message = match (true) {
                in_array($reason, ['accessNotConfigured', 'serviceDisabled'], true) => 'Enable Google Drive API for the OAuth client project.',
                $response->status() === 401 => 'Google Drive authorization expired or was revoked. Reconnect the survey Drive account.',
                $response->status() === 404 => 'Drive folder was not found or is not shared with the connected Google account. Check the folder ID and sharing.',
                $response->status() === 403 => 'Google Drive denied folder access. Check read-only authorization, folder sharing and whether Drive API is enabled.',
                $response->status() === 429 => 'Google Drive rate limit reached. The previous successful progress is retained; retry later.',
                default => 'Google Drive returned HTTP '.$response->status().'. Previous successful progress is retained.',
            };
            throw new RuntimeException($message);
        }

        return $response;
    }

    public function inventory(string $folder): array
    {
        $pending = [$folder];
        $visited = [];
        $files = [];
        while ($pending) {
            $id = array_shift($pending);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            if (count($visited) > 1000) {
                throw new RuntimeException('Too many nested survey folders.');
            }
            foreach ($this->children($id) as $file) {
                if ($file['mimeType'] === 'application/vnd.google-apps.folder') {
                    $pending[] = $file['id'];
                } elseif (in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['pdf', 'gpx', 'kmz'], true)) {
                    $files[$file['id']] = $file;
                }
            }
        }
        ksort($files);

        return array_values($files);
    }

    public function download(array $file, string $path): void
    {
        $this->assertId($file['id']);
        $maximum = (int) config('survey_progress.max_file_bytes');
        if (empty($file['size']) || (int) $file['size'] > $maximum) {
            throw new RuntimeException('Source file has an unavailable or excessive size.');
        }
        $response = Http::withToken($this->token())->timeout(120)->withOptions([
            'sink' => $path,
            'progress' => function ($total, $downloaded) use ($maximum) {
                if ($downloaded > $maximum) {
                    throw new RuntimeException('Download exceeds the source size limit.');
                }
            },
        ])->get('https://www.googleapis.com/drive/v3/files/'.$file['id'], ['alt' => 'media', 'supportsAllDrives' => 'true']);
        if (! $response->successful() || ! is_file($path) || filesize($path) !== (int) $file['size']) {
            throw new RuntimeException('Incomplete source download.');
        }
        if (empty($file['md5Checksum']) || ! hash_equals($file['md5Checksum'], md5_file($path))) {
            throw new RuntimeException('Source checksum changed or is unavailable. Synchronize again.');
        }
    }

    public function assertId(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{5,200}$/D', $id)) {
            throw new RuntimeException('Invalid Google Drive identifier.');
        }
    }
}
