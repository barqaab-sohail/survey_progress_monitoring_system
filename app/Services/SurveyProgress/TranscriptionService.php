<?php

namespace App\Services\SurveyProgress;

use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Transcription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TranscriptionService
{
    public function parse(string $text, bool $reviewed): array
    {
        $networks = [];
        foreach (preg_split('/\R/', $text) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = array_map('trim', str_getcsv($line, ',', '"', ''));
            if (count($values) !== 5) {
                throw ValidationException::withMessages(['records' => 'Line '.($index + 1).': enter transformer GPS_No, Start GPS_No, End GPS_No, PDF page, PDF row.']);
            }
            [$root, $start, $end, $page, $row] = $values;
            foreach (['transformer' => $root, 'Start' => $start, 'End' => $end] as $label => $reference) {
                if (strlen($reference) > 255 || $reviewed && ! preg_match('/^\d{11}$/D', $reference)) {
                    throw ValidationException::withMessages(['records' => 'Line '.($index + 1).': '.$label.' must contain a complete 11-digit GPS reference before approval.']);
                }
            }
            if (! ctype_digit($page) || (int) $page < 1 || (int) $page > 10000 || $row === '' || strlen($row) > 100) {
                throw ValidationException::withMessages(['records' => 'Line '.($index + 1).': enter a valid PDF page and row reference.']);
            }
            if ($reviewed && $start === $end) {
                throw ValidationException::withMessages(['records' => 'Line '.($index + 1).': Start and End must be different poles.']);
            }
            $networks[$root] ??= ['root' => $root, 'spans' => []];
            $networks[$root]['spans'][] = ['start' => $start, 'end' => $end, 'source_page' => (int) $page, 'source_row' => $row];
            if ($index > 2000) {
                throw ValidationException::withMessages(['records' => 'A transcription cannot exceed 2,000 rows.']);
            }
        }
        if (! $networks) {
            throw ValidationException::withMessages(['records' => 'Enter at least one documented S/E span.']);
        }

        return ['networks' => array_values($networks)];
    }

    public function save(ProgressFeeder $state, User $actor, array $data): Transcription
    {
        abort_unless($actor->hasRole('super_admin'), 403);

        return DB::transaction(function () use ($state, $actor, $data) {
            $locked = ProgressFeeder::lockForUpdate()->findOrFail($state->id);
            abort_unless($locked->synced_at && ! in_array($locked->sync_status, ['failed', 'mapping_required'], true), 422, 'Synchronize and resolve mapping first.');
            $analysis = app(SurveyInventory::class)->analyze($locked->files()->where('active', true)->get()->pluck('metadata')->all());
            $pair = $analysis['pairs'][$data['survey_key']] ?? null;
            abort_unless($pair && ! empty($pair['pdf']['md5Checksum']) && ! empty($pair['gpx']['md5Checksum']), 422, 'Submission is unmatched, conflicting, or lacks checksums. Synchronize again.');
            abort_unless(hash_equals($pair['pdf']['md5Checksum'], $data['pdf_checksum']) && hash_equals($pair['gpx']['md5Checksum'], $data['gpx_checksum']), 409, 'Sources changed. Reload and review the current PDF and GPX.');
            $latest = Transcription::where('progress_feeder_id', $locked->id)->where('survey_key', $data['survey_key'])->latest('version')->first();
            abort_unless(($latest?->version ?? 0) === (int) $data['version'], 409, 'Another transcription was saved. Reload before correcting it.');
            $reviewed = (bool) ($data['reviewed'] ?? false);
            $parsed = $this->parse($data['records'], $reviewed);
            $quantity = (int) substr($data['survey_key'], strrpos($data['survey_key'], '_') + 1);
            if ($reviewed && count($parsed['networks']) !== $quantity) {
                throw ValidationException::withMessages(['records' => 'This submission reports '.$quantity.' transformers. Review networks for all '.$quantity.' distinct historical transformer GPS references before approval.']);
            }
            $record = Transcription::create(['progress_feeder_id' => $locked->id, 'survey_key' => $data['survey_key'], 'version' => ($latest?->version ?? 0) + 1,
                'pdf_drive_id' => $pair['pdf']['id'], 'pdf_checksum' => $pair['pdf']['md5Checksum'], 'gpx_drive_id' => $pair['gpx']['id'], 'gpx_checksum' => $pair['gpx']['md5Checksum'],
                'data' => $parsed + ['records' => $data['records']], 'entered_by' => $actor->id, 'reviewed_by' => $reviewed ? $actor->id : null, 'reviewed_at' => $reviewed ? now() : null, 'remarks' => $data['remarks'] ?? null]);
            $locked->update(['length_status' => $locked->calculated_at ? 'stale' : 'not_calculated']);

            return $record;
        });
    }
}
