<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use Illuminate\Support\Facades\DB;
use LogicException;

class SnapshotService
{
    public function capture(SurveyBatch $batch, int $actorId): Revision
    {
        return DB::transaction(function () use ($batch, $actorId): Revision {
            $locked = SurveyBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($locked);
            $hash = $this->hash($snapshot);
            $existing = $locked->revisions()->where('number', $locked->revision)->first();
            if ($existing) {
                if (! hash_equals($existing->sha256, $hash)) {
                    throw new LogicException('This revision has already been frozen with different data. Advance the batch revision before capturing edits.');
                }

                return $existing;
            }

            return Revision::create([
                'batch_id' => $locked->id, 'number' => $locked->revision,
                'snapshot' => $snapshot, 'sha256' => $hash,
                'created_by' => $actorId, 'frozen_at' => now('UTC'),
            ]);
        });
    }

    /** An editing transaction owns revision advancement; this only revokes approval. */
    public function invalidate(SurveyBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = SurveyBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['approved_revision_id' => null, 'status' => 'draft'])->save();
            $locked->exports()->whereNull('superseded_at')->update(['superseded_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $batch->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function snapshot(SurveyBatch $batch): array
    {
        $batch->load(['sources', 'waypoints', 'transformers.sections.consumers', 'transformers.sections.pvRecords', 'transformers.pvRecords', 'transformers.entryRows']);
        $configuration = Configuration::where('project_id', $batch->project_id)->first();
        $template = Template::where('active', true)->whereNotNull('approved_at')->whereNotNull('approved_by')->orderByDesc('id')->first();
        $data = [
            'schema_version' => 1,
            'batch' => $batch->only(['id', 'project_id', 'feeder_id', 'survey_team_id', 'survey_date', 'revision']),
            'sources' => $batch->sources->sortBy('id')->values()->map(fn ($source) => $source->only(['id', 'kind', 'disk', 'path', 'original_name', 'sha256', 'bytes', 'mime', 'version', 'parent_source_id', 'uploaded_by', 'status', 'metadata']))->all(),
            'waypoints' => $batch->waypoints->sortBy('id')->values()->map(function ($waypoint) {
                $point = $waypoint->only(['id', 'batch_id', 'source_file_id', 'name', 'latitude', 'longitude', 'elevation', 'description', 'original_entry', 'correction', 'correction_approved_by']);
                $point['recorded_at'] = $waypoint->recorded_at?->utc()->toIso8601String();
                $point['correction_approved_at'] = $waypoint->correction_approved_at?->utc()->toIso8601String();

                return $point;
            })->all(),
            'transformers' => $batch->transformers->sortBy('id')->values()->map(function ($transformer) {
                $data = $transformer->only(['id', 'batch_id', 'code', 'capacity_kva', 'source_waypoint_id', 'header', 'original_header']);
                // Leave legacy snapshots byte-for-byte compatible when no new rows exist.
                if ($transformer->entryRows->isNotEmpty()) {
                    $data['entry_rows'] = $transformer->entryRows->map->surveyData()->values()->all();
                }
                $data['sections'] = $transformer->sections->sortBy('id')->values()->map(function ($section) {
                    $data = $section->only(['id', 'transformer_id', 'start_waypoint_id', 'end_waypoint_id', 'start_reference', 'end_reference', 'start_source_file_id', 'end_source_file_id', 'phases', 'conductors', 'equipment_ref', 'equipment_type', 'pole_class', 'pole_height', 'pole_height_unit', 'geometry', 'measured_length_m', 'measured_length_reason', 'length_approved_by', 'source_pdf_id', 'source_page', 'source_row', 'original_entry']);
                    $data['consumers'] = $section->consumers->sortBy('id')->values()->map(fn ($consumer) => $consumer->only(['id', 'section_id', 'category', 'count', 'original_value', 'demand']))->all();
                    $data['pv_records'] = $section->pvRecords->sortBy('id')->values()->map(fn ($pv) => $pv->only(array_merge(['id', 'transformer_id', 'section_id', 'reference', 'installed_capacity_kw', 'remarks', 'original_entry'], $pv->entry_row_id ? ['entry_row_id', 'service_load_kw'] : [])))->all();

                    return $data;
                })->all();
                $data['pv_records'] = $transformer->pvRecords->sortBy('id')->values()->map(fn ($pv) => $pv->only(array_merge(['id', 'transformer_id', 'section_id', 'reference', 'installed_capacity_kw', 'remarks', 'original_entry'], $pv->entry_row_id ? ['entry_row_id', 'service_load_kw'] : [])))->all();

                return $data;
            })->all(),
            'configuration' => $configuration?->only(['id', 'project_id', 'epsg', 'revision', 'settings', 'load_assumptions', 'approved_by', 'approved_at']),
            'template' => $template?->only(['id', 'code', 'version', 'sha256', 'synergee_version', 'metadata', 'approved_by', 'approved_at']),
        ];
        $data['batch']['survey_date'] = $batch->survey_date->format('Y-m-d');
        // Resolve exact references only. Retain original text and source provenance;
        // no connection is inferred from waypoint names or observation times.
        foreach ($data['transformers'] as &$transformer) {
            foreach ($transformer['sections'] as &$section) {
                foreach (['start', 'end'] as $endpoint) {
                    if (($section[$endpoint.'_waypoint_id'] ?? null) !== null) {
                        continue;
                    }
                    $reference = $section[$endpoint.'_reference'] ?? null;
                    $sourceId = $section[$endpoint.'_source_file_id'] ?? null;
                    if ($reference === null || trim((string) $reference) === '') {
                        continue;
                    }
                    $matches = app(SurveyWaypoint::class)->matches(['waypoint_reference' => $reference, 'gpx_source_id' => $sourceId,
                        'row_date' => $section['original_entry'][$endpoint.'_row_date'] ?? null], $data['waypoints']);
                    if (count($matches) === 1) {
                        $section[$endpoint.'_waypoint_id'] = $matches[0]['id'];
                        $section[$endpoint.'_source_file_id'] ??= $matches[0]['source_file_id'];
                    }
                }
            }
            unset($section);
        }
        unset($transformer);

        // JSON round-trip removes Carbon instances before hashing or persisting.
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
