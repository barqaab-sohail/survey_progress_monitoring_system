<?php

namespace App\Services\DriveStorage;

use App\Models\DriveStorageConnection;
use App\Models\DriveStorageFolder;
use App\Models\Feeder;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SurveyJsonExport
{
    public function save(User $actor, Feeder $feeder, string $json, string $filename, array $source): array
    {
        $root = config('drive_storage.json_export_folder_id');
        $connection = DriveStorageConnection::find(1);
        try {
            if (!$connection || empty($connection->token['access_token'])) {
                throw ValidationException::withMessages(['google_drive' => 'Super Admin must connect the application storage account on the Google Drive page before saving JSON.']);
            }
        } catch (DecryptException) {
            throw ValidationException::withMessages(['google_drive' => 'The saved storage connection cannot be read. Super Admin must reconnect the application storage account on the Google Drive page.']);
        }
        if (!$root || !preg_match('/^[A-Za-z0-9_-]{5,200}$/D', $root)) {
            throw ValidationException::withMessages(['google_drive' => 'The JSON export parent folder is not configured correctly.']);
        }
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $path = 'drive-storage/json-exports/'.Str::uuid().'.json';
        if (!Storage::disk('local')->put($path, $json)) {
            throw ValidationException::withMessages(['google_drive' => 'The JSON could not be staged safely. Retry after checking local storage.']);
        }
        $storage = app(FileStorage::class);
        try {
            $file = $storage->register('local', $path, $filename, 'application/json', [
                'kind' => 'survey_json', 'json_export_root' => $root, 'project_id' => $feeder->project_id,
                'feeder_id' => $feeder->id, 'feeder_code' => $feeder->feeder_code, 'feeder_name' => $feeder->feeder_name,
                'uploaded_by' => $actor->id, 'export_source' => $source,
            ], queueUpload: false);
            // A content match must never silently route a JSON to another feeder/root.
            if (($file->context['kind'] ?? null) !== 'survey_json' || ($file->context['json_export_root'] ?? null) !== $root
                || (int) ($file->context['feeder_id'] ?? 0) !== $feeder->id) {
                throw new \RuntimeException('The JSON matches a file registered to a different destination.');
            }
            $storage->transfer($file->id);
            $file->refresh();
            if ($file->status !== 'uploaded') throw new \RuntimeException('Drive has not confirmed the JSON upload.');
            app(DriveClient::class)->verifyJsonDestination($file);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['google_drive' => $exception instanceof \RuntimeException
                ? $exception->getMessage() : 'JSON could not be saved to Google Drive. Check the storage connection and folder permissions, then retry.']);
        }
        app(AuditService::class)->record($actor, 'survey.json_saved_to_drive', $file, [], [
            'feeder_id' => $feeder->id, 'drive_file_id' => $file->drive_file_id, 'root_folder_id' => $root,
            'sha256' => $file->sha256, 'source' => $source,
        ]);
        $folder = DriveStorageFolder::where('account_id', $file->account_id)->where('root_folder_id', $root)->where('feeder_id', $feeder->id)->first();
        return ['file_url' => 'https://drive.google.com/file/d/'.$file->drive_file_id.'/view',
            'folder_url' => 'https://drive.google.com/drive/folders/'.($folder?->drive_folder_id ?? $root),
            'filename' => $file->original_name];
    }
}
