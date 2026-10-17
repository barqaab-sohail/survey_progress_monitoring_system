<?php

namespace App\Services\Mdb;

use App\Jobs\Mdb\ProcessSourceFile;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SourceService
{
    public function store(SurveyBatch $batch, User $actor, UploadedFile $file, ?SourceFile $parent = null, array $metadata = []): SourceFile
    {
        $kind = strtolower($file->getClientOriginalExtension());
        $size = $file->getSize();
        $max = $kind === 'gpx' ? 10 * 1024 * 1024 : (int) config('mdb_workflow.max_pdf_mb', 100) * 1024 * 1024;
        if (! in_array($kind, ['pdf', 'gpx'], true) || ! $size || $size > $max) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF up to '.$max / 1024 / 1024 .' MB or a GPX up to 10 MB.']);
        }
        if ($parent && ($parent->batch_id !== $batch->id || $parent->kind !== $kind)) {
            throw ValidationException::withMessages(['parent_source_id' => 'Replacement must have the same batch and file type.']);
        }
        if ($parent && $parent->versions()->exists()) {
            throw ValidationException::withMessages(['parent_source_id' => 'Replace the latest version of this source to keep a linear version history.']);
        }
        $path = $file->getRealPath();
        if ($kind === 'pdf') {
            $handle = fopen($path, 'rb');
            $magic = fread($handle, 5);
            fclose($handle);
            if ($magic !== '%PDF-') {
                throw ValidationException::withMessages(['file' => 'The file content is not a PDF.']);
            }
        } else {
            app(GpxParser::class)->parse(file_get_contents($path));
        }
        $stored = 'mdb-workflow/sources/'.$batch->id.'/'.Str::uuid().'.'.$kind;
        $stream = fopen($path, 'rb');
        try {
            if (! Storage::disk('local')->put($stored, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Private source storage failed.');
            }
        } finally {
            fclose($stream);
        }
        $source = SourceFile::create(['batch_id' => $batch->id, 'kind' => $kind, 'disk' => 'local', 'path' => $stored,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
            'sha256' => hash_file('sha256', $path), 'bytes' => $size, 'mime' => $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml',
            'version' => $parent ? $parent->version + 1 : 1, 'parent_source_id' => $parent?->id, 'uploaded_by' => $actor->id,
            'status' => 'queued', 'metadata' => $metadata + ['uploaded_at_utc' => now('UTC')->toIso8601String()]]);
        if (config('drive_storage.enabled')) {
            $batch->loadMissing(['project', 'feeder']);
            $archive = app(\App\Services\DriveStorage\FileStorage::class)->register('local', $stored, $source->original_name, $source->mime, [
                'project_id' => $batch->project_id, 'project_code' => $batch->project?->code,
                'feeder_id' => $batch->feeder_id, 'feeder_code' => $batch->feeder?->feeder_code,
                'feeder_name' => $batch->feeder?->feeder_name,
                'batch_id' => $batch->id, 'source_file_id' => $source->id, 'survey_date' => $batch->survey_date?->toDateString(),
            ]);
            $source->update(['drive_stored_file_id' => $archive->id]);
        }
        app(AuditService::class)->record($actor, 'mdb.source_uploaded', $source, [], $source->toArray());
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($source) {
            try { \Illuminate\Support\Facades\Bus::dispatch((new ProcessSourceFile($source->id))->beforeCommit()); }
            catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Source inspection awaits queue recovery.', ['source_id' => $source->id]); }
        });

        return $source;
    }

    public function importDrive(SurveyBatch $batch, User $actor, string $fileId, ?SourceFile $parent = null, ?string $expectedKind = null): SourceFile
    {
        return $this->withDriveFile($actor, $fileId, fn ($file, $metadata) => $this->store($batch, $actor, $file, $parent, $metadata), $expectedKind);
    }

    public function withDriveFile(User $actor, string $fileId, callable $callback, ?string $expectedKind = null): mixed
    {
        if (! preg_match('/^[A-Za-z0-9_-]{10,200}$/', $fileId) || ! config('services.google_drive.enabled') || ! $actor->google_drive_token) {
            throw ValidationException::withMessages(['drive_file_id' => 'Connect Google Drive and supply an authorized Drive file ID.']);
        }
        $token = $actor->google_drive_token;
        if (($token['expires_at'] ?? 0) <= now()->timestamp + 30) {
            if (empty($token['refresh_token'])) {
                throw ValidationException::withMessages(['drive_file_id' => 'Reconnect Google Drive; the access token has expired.']);
            }
            $refreshed = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_drive.client_id'), 'client_secret' => config('services.google_drive.client_secret'),
                'grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'],
            ])->throw()->json();
            $token['access_token'] = $refreshed['access_token'];
            $token['expires_at'] = now()->timestamp + (int) ($refreshed['expires_in'] ?? 3600);
            $actor->google_drive_token = $token;
            $actor->save();
        }
        $client = Http::withToken($token['access_token'])->timeout(60);
        $info = $client->get('https://www.googleapis.com/drive/v3/files/'.$fileId, ['fields' => 'id,name,size,mimeType,version,capabilities(canDownload)'])->throw()->json();
        $kind = strtolower(pathinfo($info['name'] ?? '', PATHINFO_EXTENSION));
        if (! in_array($kind, ['pdf', 'gpx'], true) || ($expectedKind && $kind !== $expectedKind)) {
            throw ValidationException::withMessages(['drive_file_id' => 'Choose a '.($expectedKind ?? 'PDF or GPX').' file.']);
        }
        $limit = $kind === 'gpx' ? 10 : (int) config('mdb_workflow.max_pdf_mb', 100);
        if (empty($info['capabilities']['canDownload']) || empty($info['size']) || (int) $info['size'] > $limit * 1024 * 1024) {
            throw ValidationException::withMessages(['drive_file_id' => 'Drive file is not downloadable or exceeds the source limit.']);
        }
        $temp = tempnam(sys_get_temp_dir(), 'mdb-drive-');
        try {
            $client->withOptions(['sink' => $temp])->get('https://www.googleapis.com/drive/v3/files/'.$fileId, ['alt' => 'media'])->throw();

            if (filesize($temp) > $limit * 1024 * 1024) {
                throw ValidationException::withMessages(['drive_file_id' => 'Drive file exceeds the source limit.']);
            }

            return $callback(new UploadedFile($temp, $info['name'], $info['mimeType'], null, true),
                ['drive_file_id' => $fileId, 'drive_version' => $info['version'] ?? null]);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
