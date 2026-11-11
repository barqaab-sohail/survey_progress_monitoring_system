<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\FeederKmzExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FeederKmzHistoryService
{
    public function create(Feeder $feeder, User $actor): FeederKmzExport
    {
        $artifact = app(FeederKmzService::class)->generate($feeder);
        $path = 'feeder-kmz-exports/'.$feeder->id.'/'.Str::uuid().'.kmz';
        try {
            $disk = Storage::disk('local');
            $stream = fopen($artifact['path'], 'rb');
            try {
                if (! $disk->put($path, $stream)) throw new \RuntimeException('Unable to retain the KMZ export.');
            } finally { if (is_resource($stream)) fclose($stream); }
            return DB::transaction(function () use ($feeder, $actor, $artifact, $path): FeederKmzExport {
                // Serialize history comparisons for simultaneous exports of the same feeder.
                Feeder::whereKey($feeder->id)->lockForUpdate()->firstOrFail();
                $previous = FeederKmzExport::where('feeder_id', $feeder->id)->latest('id')->first();
                $networks = array_map(fn ($network) => array_intersect_key($network, array_flip([
                    'registered_transformer_id', 'code', 'batch_id', 'revision', 'revision_sha256', 'survey_date', 'approval_date',
                ])), $artifact['prepared']['networks']);
                $record = FeederKmzExport::create(['feeder_id' => $feeder->id, 'created_by' => $actor->id,
                    'filename' => 'Feeder_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $feeder->feeder_code).'_HT_Approved_LT_'.now('Asia/Karachi')->format('Ymd_His').'.kmz',
                    'disk' => 'local', 'path' => $path, 'sha256' => hash_file('sha256', $artifact['path']), 'bytes' => filesize($artifact['path']),
                    'baseline_sha256' => $artifact['baseline_sha256'], 'counts' => $artifact['prepared']['counts'],
                    'networks' => $networks, 'coverage' => $artifact['prepared']['coverage'], 'warnings' => $artifact['prepared']['warnings'],
                    'changes' => $this->changes($previous?->networks ?? [], $networks)]);
                app(AuditService::class)->record($actor, 'feeder.ht_lt_kmz_exported', $feeder, [],
                    ['export_id' => $record->id, 'sha256' => $record->sha256, 'counts' => $record->counts]);
                return $record;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        } finally { @unlink($artifact['path']); }
    }

    public function changes(array $before, array $after): array
    {
        $old = array_column($before, null, 'registered_transformer_id');
        $new = array_column($after, null, 'registered_transformer_id');
        return ['added' => array_values(array_column(array_diff_key($new, $old), 'code')),
            'removed' => array_values(array_column(array_diff_key($old, $new), 'code')),
            'updated' => array_values(array_map(fn ($row) => $row['code'], array_filter($new,
                fn ($row, $id) => isset($old[$id]) && ($row['revision_sha256'] !== $old[$id]['revision_sha256'] || $row['code'] !== $old[$id]['code']), ARRAY_FILTER_USE_BOTH)))];
    }

    public function path(FeederKmzExport $record): string
    {
        $path = Storage::disk($record->disk)->path($record->path);
        abort_unless(is_file($path), 422, 'The archived KMZ file is unavailable. Restore it from backup; this history record is retained.');
        abort_unless(hash_equals($record->sha256, hash_file('sha256', $path)), 422, 'The archived KMZ checksum changed. Restore the original file from backup.');
        return $path;
    }
}
