<?php

namespace App\Services;

use App\Models\FieldSurvey;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\MobileGpxLink;
use App\Models\MobileGpxSource;
use App\Services\Mdb\ApprovedSchema;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\EntryLookups;
use App\Services\Mdb\GenerationMode;
use App\Services\Mdb\SchemaTemplate;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\SynergeeTransfer;
use App\Support\FieldSurveyPairs;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Adapts confirmed mobile observations to the existing offline SynerGEE mapper. */
class MobileSurveySnapshot
{
    public function build(FieldSurvey $survey, array $report): array
    {
        $survey->loadMissing(['team', 'feeder.gridStation']);
        $configuration = Configuration::where('project_id', $survey->team->project_id)->first();
        $templateId = $configuration?->settings['template_id'] ?? null;
        $query = Template::where('active', true);
        if (! GenerationMode::simple($configuration) || ! $templateId) {
            $query->whereNotNull('approved_by')->whereNotNull('approved_at');
        }
        $template = $templateId ? $query->find($templateId) : $query->latest('id')->first();
        if (! $template && GenerationMode::simple($configuration) && ! $templateId) {
            $template = app(SchemaTemplate::class)->current();
        }
        $sources = MobileGpxSource::whereIn('id', array_column($report['sources'], 'id'))->get();
        $points = $pointIds = [];
        foreach ($report['mapping'] as $mapped) {
            if (! $mapped['point']) {
                continue;
            }
            $point = $mapped['point'];
            $key = $point['source_id'].':'.$point['point_index'];
            if (isset($pointIds[$key])) {
                continue;
            }
            $id = count($points) + 1;
            $pointIds[$key] = $id;
            $points[] = ['id' => $id, 'batch_id' => $survey->id, 'source_file_id' => $point['source_id'],
                'name' => $point['name'], 'latitude' => $point['latitude'], 'longitude' => $point['longitude'],
                'elevation' => $point['elevation'], 'recorded_at' => $point['recorded_at'],
                'original_entry' => ['source_point_index' => $point['point_index']]];
        }
        $entries = $sections = $pvs = [];
        foreach (array_values(FieldSurveyPairs::groups($survey->rows)) as $pairIndex => $indices) {
            $pair = $pairIndex + 1;
            $slots = [];
            foreach ($indices as $i) {
                $row = $survey->rows[$i];
                $mapped = $report['mapping'][$i];
                $point = $mapped['point'];
                $conductors = [];
                foreach (['R' => 'r', 'Y' => 'y', 'B' => 'b', 'N' => 'neutral'] as $phase => $key) {
                    $value = $row['conductor_'.$key] ?? null;
                    $conductors[$phase] = is_string($value) && in_array(strtolower(trim($value)), ['', '__none__', '+', '-', '–', '—', 'x', '×'], true) ? '' : $value;
                }
                $entryId = $i + 1;
                $details = [];
                foreach ($survey->solar as $pv) {
                    if (empty($row['row_id']) || ($pv['row_id'] ?? null) !== $row['row_id']) {
                        continue;
                    }
                    $details[] = ['reference' => $pv['consumer_reference'] ?? null, 'installed_capacity_kw' => $pv['installed_pv_kw'] ?? null,
                        'service_load_kw' => $pv['service_load_kw'] ?? null, 'meter_number' => $pv['meter_number'] ?? null, 'remarks' => $pv['remarks'] ?? null];
                    $pvs[] = ['id' => count($pvs) + 1, 'transformer_id' => $survey->id, 'section_id' => $pair, 'entry_row_id' => $entryId,
                        'reference' => $pv['consumer_reference'] ?? null, 'installed_capacity_kw' => $pv['installed_pv_kw'] ?? null,
                        'service_load_kw' => $pv['service_load_kw'] ?? null, 'meter_number' => $pv['meter_number'] ?? null, 'remarks' => $pv['remarks'] ?? null,
                        'original_entry' => ['manually_verified' => true, 'mobile_row_id' => $row['row_id'], 'mobile_survey_uuid' => $survey->client_uuid]];
                }
                $entry = ['id' => $entryId, 'transformer_id' => $survey->id, 'pair_number' => $pair, 'section_id' => $pair, 'designation' => $row['se'],
                    'source_pdf_id' => null, 'source_page' => null, 'source_row' => null,
                    'group_number' => $row['group'], 'row_date' => $row['date'], 'composite_identifier' => $mapped['complete_waypoint'],
                    'waypoint_reference' => $point['name'] ?? $row['gps_waypoint'], 'original_waypoint_reference' => $row['gps_waypoint'],
                    'gpx_source_id' => $point['source_id'] ?? null, 'conductors' => $conductors, 'consumers' => $row['consumers'] ?? [],
                    'consumer_phases' => $row['consumer_phases'] ?? [], 'equipment_type' => $row['equipment_type'] ?? null,
                    'pole_class' => $row['pole_class'] ?? null, 'pole_height' => $row['pole_height_ft'] ?? null, 'pole_height_unit' => 'ft',
                    'intersection' => $row['intersection'] ?? false, 'remarks' => $row['remarks'] ?? null,
                    'manually_verified' => true, 'verification_method' => 'mobile_gpx_confirmation',
                    'mobile_row_id' => $row['row_id'] ?? null, 'mobile_survey_uuid' => $survey->client_uuid, 'pv_details' => $details];
                $entries[] = $entry;
                $slots[$row['se']] = $entry + ['point_id' => $point ? $pointIds[$point['source_id'].':'.$point['point_index']] : null];
            }
            $start = $slots['S'];
            $end = $slots['E'];
            $phase = EntryLookups::phase($start['conductors']);
            $conductors = array_map(EntryLookups::libraryCode(...), $start['conductors']);
            $phases = $phase['phases'];
            if (! empty($conductors['N'])) {
                $phases[] = 'N';
            }
            $consumers = [];
            foreach (array_diff(array_keys(EntryLookups::CONSUMERS), ['pv']) as $category) {
                $values = array_filter([$start['consumers'][$category] ?? null, $end['consumers'][$category] ?? null], fn ($n) => $n !== null && $n !== '');
                $consumers[] = ['id' => count($consumers) + 1, 'section_id' => $pair, 'category' => $category, 'count' => $values === [] ? null : array_sum($values), 'demand' => null];
            }
            $sections[] = ['id' => $pair, 'transformer_id' => $survey->id,
                'start_waypoint_id' => $start['point_id'], 'end_waypoint_id' => $end['point_id'],
                'start_reference' => $start['waypoint_reference'], 'end_reference' => $end['waypoint_reference'],
                'start_source_file_id' => $start['gpx_source_id'], 'end_source_file_id' => $end['gpx_source_id'],
                'phases' => $phases, 'conductors' => $conductors, 'equipment_type' => $start['equipment_type'],
                'equipment_ref' => null, 'pole_class' => $start['pole_class'], 'pole_height' => $start['pole_height'], 'pole_height_unit' => 'ft',
                'consumers' => $consumers, 'pv_records' => array_values(array_filter($pvs, fn ($p) => $p['section_id'] === $pair)),
                'geometry' => [], 'measured_length_m' => null,
                'original_entry' => ['entry_rows_managed' => true, 'entry_pair_number' => $pair, 'manually_verified' => true,
                    'mobile_row_ids' => [$start['mobile_row_id'], $end['mobile_row_id']], 'mobile_survey_uuid' => $survey->client_uuid,
                    'entry_rows' => [$start, $end], 'start_survey_identifier' => $start['composite_identifier'], 'end_survey_identifier' => $end['composite_identifier'],
                    'start_row_date' => $start['row_date'], 'end_row_date' => $end['row_date'], 'phase_entries_complete' => $phase['complete']]];
        }
        $header = $survey->header;
        $transformer = ['id' => $survey->id, 'code' => $survey->transformer_code, 'capacity_kva' => $header['capacity_kva'] ?? null,
            'source_waypoint_id' => null, 'entry_rows' => $entries, 'sections' => $sections, 'pv_records' => $pvs,
            'header' => ['manually_verified' => true, 'verification_method' => 'mobile_gpx_confirmation',
                'feeder_identifier' => $survey->reference_snapshot['feeder_code'] ?? $survey->feeder->feeder_code,
                'feeder_name' => $survey->reference_snapshot['feeder_name'] ?? $survey->feeder->feeder_name,
                'substation_identifier' => $survey->feeder->gridStation?->code,
                'substation_name' => $header['substation'] ?? null, 'make' => $header['transformer_make'] ?? null,
                'division' => $header['division'] ?? null, 'subdivision' => $header['sub_division'] ?? null, 'subdivision_code' => $header['sub_division_code'] ?? null,
                'location' => $header['location'] ?? null, 'mounting' => match ($header['mounting'] ?? null) {
                    'S.Pole' => 'Single Pole', 'D.Pole' => 'Double Pole', default => $header['mounting'] ?? null,
                }, 'service_category' => $header['duty'] ?? null,
                'survey_date' => $survey->survey_date->format('Y-m-d'), 'team_group' => $survey->reference_snapshot['group_code'] ?? $entries[1]['group_number'],
                'inspector' => $header['inspectors'] ?? null, 'pages' => [], 'registered_transformer_id' => $survey->transformer_id,
                'starting_pole_coordinates' => $survey->reference_snapshot['starting_pole_coordinates'] ?? ['version' => 1, 'source' => SurveyWaypoint::FIRST_PAIR_COORDINATE_SOURCE]]];
        $transformer = app(SurveyWaypoint::class)->firstPairCoordinates($transformer, $points);
        $snapshot = ['schema_version' => 1, 'batch' => ['id' => $survey->id, 'project_id' => $survey->team->project_id,
            'feeder_id' => $survey->feeder_id, 'survey_team_id' => $survey->survey_team_id, 'survey_date' => $survey->survey_date->format('Y-m-d'), 'revision' => $survey->revision],
            'mobile_survey' => ['uuid' => $survey->client_uuid, 'revision' => $survey->revision, 'sha256' => app(MobileGpxWorkflow::class)->surveyHash($survey),
                'data' => $survey->only(['header', 'rows', 'solar', 'remarks']),
                'data_sha256' => app(SnapshotService::class)->hash($survey->only(['header', 'rows', 'solar', 'remarks'])), 'linkage_report' => $report],
            'sources' => $sources->map(fn ($s) => ['id' => $s->id, 'kind' => 'gpx', 'status' => 'ready', 'original_name' => $s->original_name, 'sha256' => $s->sha256])->all(),
            'waypoints' => $points, 'generation_inputs' => [], 'transformers' => [$transformer],
            'configuration' => $configuration?->only(['id', 'project_id', 'epsg', 'revision', 'settings', 'load_assumptions', 'approved_by', 'approved_at']),
            'template' => $template?->only(['id', 'code', 'version', 'sha256', 'synergee_version', 'metadata', 'approved_by', 'approved_at'])];

        $originalConfiguration = json_decode(json_encode($snapshot['configuration'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['mobile_survey']['configuration_sha256'] = app(SnapshotService::class)->hash(['configuration' => $originalConfiguration]);

        // Match Eloquent JSON number normalization before freezing the checksum.
        return json_decode(json_encode(app(EngineeringConfiguration::class)->apply($snapshot), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function export(MobileGpxLink $link): array
    {
        $snapshot = $link->snapshot;
        if (! hash_equals($link->sha256, app(SnapshotService::class)->hash($snapshot))) {
            $this->fail('The confirmed snapshot integrity check failed.');
        }
        $configuration = Configuration::where('project_id', $snapshot['batch']['project_id'])->first();
        $currentConfiguration = $configuration?->only(['id', 'project_id', 'epsg', 'revision', 'settings', 'load_assumptions', 'approved_by', 'approved_at']);
        $currentConfiguration = json_decode(json_encode($currentConfiguration, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (($snapshot['mobile_survey']['configuration_sha256'] ?? '') !== app(SnapshotService::class)->hash(['configuration' => $currentConfiguration])) {
            $this->fail('Project engineering settings changed. Preview and confirm again to freeze the current settings.');
        }
        $template = Template::find($snapshot['template']['id'] ?? null);
        if (! $template || ! $template->active || ! hash_equals($snapshot['template']['sha256'], $template->sha256)) {
            $this->fail('Configure an active MDB template, then preview and confirm the linkage again.');
        }
        $currentTemplate = json_decode(json_encode($template->only(['id', 'code', 'version', 'sha256', 'synergee_version', 'metadata', 'approved_by', 'approved_at']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (app(SnapshotService::class)->hash($currentTemplate) !== app(SnapshotService::class)->hash($snapshot['template'])) {
            $this->fail('The template settings or approval changed. Preview and confirm again.');
        }
        $bytes = Storage::disk($template->disk)->get($template->path);
        if (! is_string($bytes) || ! hash_equals($template->sha256, hash('sha256', $bytes))) {
            $this->fail('The MDB template original is missing or changed.');
        }
        $content = ['format' => 'hazeco-synergee-transfer', 'version' => 1, 'schema_sha256' => ApprovedSchema::fingerprint(),
            'source' => ['kind' => 'mobile_survey', 'survey_uuid' => $link->survey->client_uuid, 'batch_id' => $snapshot['batch']['id'],
                'revision_id' => $link->id, 'revision_number' => $link->survey_revision, 'revision_sha256' => $link->sha256,
                'confirmed_by' => $link->confirmed_by, 'confirmed_at' => $link->created_at->toIso8601String(),
                'confirmation_note' => $link->confirmation_note, 'exported_at' => now()->utc()->toIso8601String()],
            'scope' => ['transformer_id' => $snapshot['transformers'][0]['id'], 'transformer_codes' => [$snapshot['transformers'][0]['code']]],
            'snapshot' => $snapshot, 'template_base64' => base64_encode($bytes)];
        $package = $content + ['sha256' => app(SnapshotService::class)->hash($content)];
        if (strlen(json_encode($package, JSON_THROW_ON_ERROR)) > SynergeeTransfer::MAX_BYTES) {
            $this->fail('This package exceeds the 30 MB offline conversion limit.');
        }
        $package = app(SynergeeTransfer::class)->read(json_encode($package, JSON_THROW_ON_ERROR));
        $inspection = app(SynergeeTransfer::class)->inspect($package);
        if ($inspection['issues']) {
            $this->fail('Resolve the following model inputs, then confirm again: '.implode(' ', array_column($inspection['issues'], 'message')));
        }

        return $package;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['gpx' => $message]);
    }
}
