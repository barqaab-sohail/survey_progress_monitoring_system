<?php

namespace App\Services\Mdb;

use App\Models\Feeder;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use Illuminate\Validation\ValidationException;

class TransformerIdentity
{
    public static function key(string $code): string
    {
        $code = preg_replace('/[‐‑‒–—−]/u', '-', mb_strtolower($code));

        return preg_replace('/\s+/u', '', $code);
    }

    /** Called inside the save transaction, after locking its batch. */
    public function assertAvailable(SurveyBatch $batch, string $code, ?NetworkTransformer $record = null): void
    {
        // Existing duplicate records remain editable; this prevents new copies and renames.
        if ($record && self::key($record->code) === self::key($code)) {
            return;
        }
        // Different batches must share a lock so simultaneous saves cannot both pass.
        Feeder::whereKey($batch->feeder_id)->lockForUpdate()->firstOrFail();
        $key = self::key($code);
        $existing = NetworkTransformer::whereHas('batch', fn ($query) => $query
            ->where('project_id', $batch->project_id)->where('feeder_id', $batch->feeder_id))
            ->when($record, fn ($query) => $query->whereKeyNot($record->id))
            ->orderBy('batch_id')->lockForUpdate()->get(['id', 'batch_id', 'code'])
            ->first(fn ($transformer) => self::key($transformer->code) === $key);
        if ($existing) {
            $message = $existing->batch_id === $batch->id
                ? 'This transformer is already added to this survey. Select its saved header in Active transformer to edit it.'
                : 'This transformer is already added in batch #'.$existing->batch_id.' for this project and feeder. Edit the existing record instead of adding it again.';
            throw ValidationException::withMessages(['code' => $message]);
        }
    }
}
