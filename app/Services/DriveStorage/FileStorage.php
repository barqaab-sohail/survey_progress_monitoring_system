<?php

namespace App\Services\DriveStorage;

use App\Jobs\UploadDriveFile;
use App\Models\DriveStorageConnection;
use App\Models\DriveStoredFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FileStorage
{
    public function register(string $disk, string $path, string $name, string $mime, array $context): DriveStoredFile
    {
        $full = Storage::disk($disk)->path($path);
        if (! is_file($full)) {
            throw new RuntimeException('Private file staging failed.');
        }
        $sha256 = hash_file('sha256', $full);
        $bytes = filesize($full);
        $file = DB::transaction(function () use ($disk, $path, $name, $mime, $context, $sha256, $bytes, $full) {
            DB::table('drive_file_hash_claims')->insertOrIgnore([
                'sha256' => $sha256, 'drive_stored_file_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $claim = DB::table('drive_file_hash_claims')->where('sha256', $sha256)->lockForUpdate()->first();
            $existing = $claim->drive_stored_file_id ? DriveStoredFile::find($claim->drive_stored_file_id) : null;
            if (! $existing) {
                $existing = DriveStoredFile::where('sha256', $sha256)->where('status', 'uploaded')->orderBy('id')->first()
                    ?? DriveStoredFile::where('sha256', $sha256)->whereIn('status', ['pending', 'failed'])->orderBy('id')->first();
            }
            if ($existing) {
                // A failed transfer may have lost its staging copy during an older
                // batch deletion. Keep the verified re-upload instead of discarding it.
                Cache::lock('drive-storage:file:'.$existing->id, 600)->block(10, function () use ($existing, $disk, $path, $full, $bytes) {
                    $existing->refresh();
                    if ($existing->status === 'uploaded') {
                        return;
                    }
                    try {
                        $this->verifyLocal($existing, Storage::disk($existing->local_disk)->path($existing->local_path));
                    } catch (RuntimeException) {
                        $existing->update(['local_disk' => $disk, 'local_path' => $path,
                            'bytes' => $bytes, 'md5' => md5_file($full), 'status' => 'pending',
                            'local_deleted_at' => null, 'last_error' => null, 'next_attempt_at' => null]);
                    }
                });
                if (! $claim->drive_stored_file_id) {
                    DB::table('drive_file_hash_claims')->where('sha256', $sha256)->update([
                        'drive_stored_file_id' => $existing->id, 'updated_at' => now(),
                    ]);
                }
                $existing->setAttribute('duplicate_content', true);
                $existing->syncOriginalAttribute('duplicate_content');
                $existing->setAttribute('duplicate_details', $this->duplicateDetails($existing));
                $existing->syncOriginalAttribute('duplicate_details');
                return $existing;
            }
            $safeName = mb_substr(basename(str_replace('\\', '/', $name)), 0, 255);
            $nameQuery = DriveStoredFile::where('original_name', $safeName)->where('sha256', '!=', $sha256);
            if ($nameQuery->exists()) {
                $extension = pathinfo($safeName, PATHINFO_EXTENSION);
                $stem = pathinfo($safeName, PATHINFO_FILENAME).' ('.substr($sha256, 0, 8).')';
                $safeName = mb_substr($stem.($extension !== '' ? '.'.$extension : ''), 0, 255);
                $suffix = 2;
                while (DriveStoredFile::where('original_name', $safeName)->exists()) {
                    $safeName = mb_substr(pathinfo($safeName, PATHINFO_FILENAME).' '.$suffix.($extension !== '' ? '.'.$extension : ''), 0, 255);
                    $suffix++;
                }
            }
            $created = DriveStoredFile::create(['uuid' => (string) Str::uuid(), 'local_disk' => $disk, 'local_path' => $path,
                'original_name' => $safeName, 'mime' => $mime, 'bytes' => $bytes, 'sha256' => $sha256,
                'md5' => md5_file($full), 'context' => $context]);
            DB::table('drive_file_hash_claims')->where('sha256', $sha256)->update([
                'drive_stored_file_id' => $created->id, 'updated_at' => now(),
            ]);
            return $created;
        });
        if (! $file->wasRecentlyCreated && ($file->local_disk !== $disk || $file->local_path !== $path)) {
            // Do not keep a second private staging copy after a content match is established.
            Storage::disk($disk)->delete($path);
        }
        if ($file->status !== 'uploaded') {
            // Re-dispatching a canonical pending/failed row is safe: transfer() serializes by file ID and verifies Drive.
            DB::afterCommit(function () use ($file) {
                try { UploadDriveFile::dispatch($file->id); }
                catch (\Throwable) { Log::warning('Drive transfer queued for scheduler recovery.', ['stored_file_id' => $file->id]); }
            });
        }
        if ($file->wasRecentlyCreated) {
            $file->setAttribute('duplicate_content', false);
            $file->syncOriginalAttribute('duplicate_content');
        }
        return $file;
    }

    private function duplicateDetails(DriveStoredFile $file): array
    {
        $context = $file->context ?? [];
        $feeder = ! empty($context['feeder_id']) ? \App\Models\Feeder::find($context['feeder_id']) : null;
        $uploader = ! empty($context['uploaded_by']) ? \App\Models\User::find($context['uploaded_by']) : null;
        if (! $uploader && ! empty($context['source_file_id'])) $uploader = \App\Models\Mdb\SourceFile::find($context['source_file_id'])?->uploadedBy;
        return ['name' => $file->original_name, 'feeder' => $feeder ? trim($feeder->feeder_code.' '.$feeder->feeder_name) : ($context['feeder_code'] ?? 'Unknown feeder'),
            'uploaded_at' => $file->uploaded_at?->format('d M Y H:i') ?? 'Pending Drive confirmation', 'uploader' => $uploader?->name ?? 'Unknown (legacy record)'];
    }

    public function transfer(int $id): void
    {
        Cache::lock('drive-storage:file:'.$id, 600)->block(10, function () use ($id) {
            $file = DriveStoredFile::findOrFail($id);
            if ($file->status === 'uploaded') {
                $this->cleanup($file);

                return;
            }
            if (! config('drive_storage.enabled')) {
                throw new RuntimeException('Automatic Drive storage is disabled; the staged file is retained.');
            }
            $file->increment('attempts');
            $stage = 'storage_account';
            try {
                $connection = DriveStorageConnection::find(1);
                if (! $connection) {
                    throw new RuntimeException('Connect the application storage account.');
                }
                if ($file->account_id && $file->account_id !== $connection->account_id) {
                    throw new RuntimeException('Reconnect the original storage account.');
                }
                $stage = 'staged_original';
                $path = Storage::disk($file->local_disk)->path($file->local_path);
                $this->verifyLocal($file, $path);
                $client = app(DriveClient::class);
                if (! $file->drive_file_id) {
                    $stage = 'drive_identity';
                    $file->update(['drive_file_id' => $client->generateId(), 'account_id' => $connection->account_id]);
                }
                $stage = 'drive_upload';
                $client->upload($file, $path);
                // Commit the remote pointer before deleting the only local recovery copy.
                $file->update(['status' => 'uploaded', 'uploaded_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
            } catch (\Throwable $exception) {
                Log::warning('Drive storage transfer failed.', ['stored_file_id' => $id,
                    'stage' => $stage, 'exception_type' => $exception::class]);
                $file->update(['status' => 'failed', 'last_error' => 'Drive transfer failed. The private staged original is retained; check storage connection, quota and worker, then retry.',
                    'next_attempt_at' => now()->addMinutes(min(360, 2 ** min($file->attempts, 8)))]);
                // Do not log Google responses, OAuth credentials or resumable session URLs.
                throw new RuntimeException('Drive transfer failed for stored file #'.$id.'. The original is retained for recovery.');
            }
            $this->cleanup($file);
        });
    }

    public function cleanup(DriveStoredFile $file): void
    {
        if ($file->status !== 'uploaded') {
            return;
        }
        $disk = Storage::disk($file->local_disk);
        if ($disk->exists($file->local_path) && ! $disk->delete($file->local_path)) {
            throw new RuntimeException('Confirmed Drive file is awaiting local staging cleanup.');
        }
        $file->update(['local_deleted_at' => now()]);
    }

    public function withPath(?DriveStoredFile $file, string $disk, string $path, callable $callback): mixed
    {
        if (! $file) {
            return $callback(Storage::disk($disk)->path($path));
        }
        $temporary = $this->snapshot($file);
        try {
            return $callback($temporary);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function snapshot(DriveStoredFile $file): string
    {
        $temporary = null;
        $stage = 'temporary_storage';
        try {
            $workDirectory = Storage::disk('local')->path('drive-storage/work');
            if (! Storage::disk('local')->makeDirectory('drive-storage/work') || ! is_writable($workDirectory)) {
                throw new RuntimeException('Private temporary storage is not writable.');
            }
            $temporary = tempnam($workDirectory, 'source-');
            if ($temporary === false) {
                throw new RuntimeException('Private temporary file could not be created.');
            }
            $stage = 'storage_lock';
            Cache::lock('drive-storage:file:'.$file->id, 600)->block(10, function () use ($file, $temporary, &$stage) {
                $stage = 'storage_record';
                $file->refresh();
                if ($file->status === 'uploaded') {
                    $stage = 'drive_account';
                    if ($file->account_id !== DriveStorageConnection::find(1)?->account_id) {
                        throw new RuntimeException('The original Drive storage account must be connected.');
                    }
                    $stage = 'drive_download';
                    app(DriveClient::class)->download($file, $temporary);
                } else {
                    $stage = 'staged_original';
                    $source = Storage::disk($file->local_disk)->path($file->local_path);
                    if (! is_file($source) || ! copy($source, $temporary)) {
                        throw new RuntimeException('The staged original is unavailable.');
                    }
                }
                $stage = 'integrity_verification';
                $this->verifyLocal($file, $temporary);
            });

            return $temporary;
        } catch (\Throwable $exception) {
            // Record actionable context without OAuth tokens, API bodies or private paths.
            Log::warning('Source storage read failed.', [
                'stored_file_id' => $file->id,
                'stage' => $stage,
                'exception_type' => $exception::class,
            ]);
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Source file is temporarily unavailable or failed integrity verification. Please retry or contact the administrator.');
        }
    }

    public function response(?DriveStoredFile $file, string $disk, string $path, string $name, string $mime, bool $download = false)
    {
        try {
            $full = $file ? $this->snapshot($file) : Storage::disk($disk)->path($path);
        } catch (RuntimeException) {
            abort(503, 'Source storage is temporarily unavailable. Please retry later.');
        }
        abort_unless(is_file($full), 404);
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        $response = response()->download($full, $name, $headers, $download ? 'attachment' : 'inline');
        if ($file) {
            $response->deleteFileAfterSend(true);
        }

        return $response;
    }

    private function verifyLocal(DriveStoredFile $file, string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) !== $file->bytes || ! hash_equals($file->sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('Source integrity verification failed.');
        }
    }
}
