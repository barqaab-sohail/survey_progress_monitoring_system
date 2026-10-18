<?php

namespace App\Services\DriveStorage;

use App\Models\AuditLog;
use App\Models\DriveStoredFile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DuplicateFiles
{
    public function groups(): array
    {
        $groups = DriveStoredFile::query()->where('status', 'uploaded')
            ->where(fn ($q) => $q->whereRaw('LOWER(original_name) LIKE ?', ['%.pdf'])->orWhereRaw('LOWER(original_name) LIKE ?', ['%.gpx']))
            ->orderBy('uploaded_at')->orderBy('id')->get()->groupBy('sha256')->filter(fn ($rows) => $rows->count() > 1);
        return $groups->map(function ($rows, $hash) {
            $files = $rows->map(fn ($file) => $this->details($file))->values();
            return ['sha256' => $hash, 'files' => $files, 'copies' => $files->count(),
                'recoverable_bytes' => $rows->sum('bytes') - $rows->first()->bytes,
                'consistent' => $rows->pluck('bytes')->unique()->count() === 1 && $rows->every(fn ($f) => $f->drive_file_id && $f->account_id === $rows->first()->account_id)];
        })->values()->all();
    }

    public function cleanup(string $hash, User $actor): array
    {
        return DB::transaction(function () use ($hash, $actor) {
            $rows = DriveStoredFile::where('sha256', $hash)->where('status', 'uploaded')->orderBy('uploaded_at')->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() < 2) throw new RuntimeException('This hash no longer has confirmed duplicate Drive files. Refresh the report.');
            if ($rows->pluck('bytes')->unique()->count() !== 1 || $rows->pluck('account_id')->unique()->count() !== 1 || $rows->contains(fn ($f) => ! $f->drive_file_id)) {
                throw new RuntimeException('The copies do not have matching verified sizes and accounts. No files were changed.');
            }
            $keeper = $rows->first();
            $duplicates = $rows->slice(1)->values();
            $ids = $duplicates->pluck('id')->all();
            $detail = ['sha256' => $hash, 'keeper' => $this->details($keeper), 'duplicates' => $duplicates->map(fn ($f) => $this->details($f))->all()];
            $this->repoint('mdb_workflow_sources', 'drive_stored_file_id', $ids, $keeper->id);
            $this->repoint('field_survey_attachments', 'drive_stored_file_id', $ids, $keeper->id);
            $this->repoint('field_survey_test_attachments', 'drive_stored_file_id', $ids, $keeper->id);
            $this->repoint('transformer_mdb_projects', 'pdf_drive_stored_file_id', $ids, $keeper->id);
            $this->repoint('transformer_mdb_projects', 'gpx_drive_stored_file_id', $ids, $keeper->id);
            $actor->loadMissing('roles');
            app(AuditService::class)->record($actor, 'drive.duplicate_cleanup_requested', $keeper, [], $detail, 'Super Admin confirmed duplicate content cleanup.');
            return ['keeper_id' => $keeper->id, 'duplicates' => $duplicates->all(), 'bytes' => $duplicates->sum('bytes'), 'hash' => $hash];
        });
    }

    public function finishCleanup(array $plan): void
    {
        $keeper = DriveStoredFile::findOrFail($plan['keeper_id']);
        app(DriveClient::class)->verifyRemote($keeper);
        foreach ($plan['duplicates'] as $file) {
            app(DriveClient::class)->verifyRemote($file);
            app(DriveClient::class)->deleteVerified($file);
            DB::transaction(function () use ($file, $plan) {
                $current = DriveStoredFile::lockForUpdate()->find($file->id);
                if (! $current) return;
                if (DB::table('mdb_workflow_sources')->where('drive_stored_file_id', $current->id)->exists()
                    || DB::table('field_survey_attachments')->where('drive_stored_file_id', $current->id)->exists()
                    || DB::table('field_survey_test_attachments')->where('drive_stored_file_id', $current->id)->exists()
                    || DB::table('transformer_mdb_projects')->where(fn ($q) => $q->where('pdf_drive_stored_file_id', $current->id)->orWhere('gpx_drive_stored_file_id', $current->id))->exists()) {
                    throw new RuntimeException('A valid record still references a duplicate. It was retained for safety.');
                }
                if ($current->local_disk && Storage::disk($current->local_disk)->exists($current->local_path)) Storage::disk($current->local_disk)->delete($current->local_path);
                $current->delete();
            });
        }
        AuditLog::where('action', 'drive.duplicate_cleanup_requested')->where('auditable_type', DriveStoredFile::class)
            ->where('auditable_id', $plan['keeper_id'])->latest('id')->limit(1)->update([
                'new_values' => json_encode(['sha256' => $plan['hash'], 'keeper_id' => $plan['keeper_id'],
                    'removed_duplicate_ids' => collect($plan['duplicates'])->pluck('id')->all(), 'recovered_bytes' => $plan['bytes'], 'completed_at' => now()->toIso8601String()]),
            ]);
    }

    private function repoint(string $table, string $column, array $ids, int $keeper): void
    {
        DB::table($table)->whereIn($column, $ids)->update([$column => $keeper]);
    }

    private function details(DriveStoredFile $file): array
    {
        $context = $file->context ?? [];
        $feeder = ! empty($context['feeder_id']) ? \App\Models\Feeder::find($context['feeder_id']) : null;
        $uploader = ! empty($context['uploaded_by']) ? User::find($context['uploaded_by']) : null;
        if (! $uploader && ! empty($context['source_file_id'])) $uploader = \App\Models\Mdb\SourceFile::find($context['source_file_id'])?->uploadedBy;
        if (! $uploader && ! empty($context['transformer_workspace_id'])) $uploader = \App\Models\TransformerMdbProject::find($context['transformer_workspace_id'])?->creator;
        return ['id' => $file->id, 'name' => $file->original_name, 'feeder' => $feeder ? trim($feeder->feeder_code.' '.$feeder->feeder_name) : ($context['feeder_code'] ?? 'Unknown feeder'),
            'uploaded_at' => $file->uploaded_at, 'uploader' => $uploader?->name ?? 'Unknown (legacy record)', 'bytes' => $file->bytes, 'drive_file_id' => $file->drive_file_id];
    }
}
