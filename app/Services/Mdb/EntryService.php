<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkSection;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\PdfLine;
use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SurveyBatch;
use Illuminate\Validation\ValidationException;

class EntryService
{
    public function synchronizeTransformerLocation(NetworkTransformer $transformer): void
    {
        $value = app(SurveyWaypoint::class)->transformerLocation([
            'header' => $transformer->header ?? [], 'source_waypoint_id' => $transformer->source_waypoint_id,
            'entry_rows' => $transformer->entryRows()->get()->map->surveyData()->all(),
        ], $transformer->batch->waypoints()->get()->toArray());
        $transformer->update(['source_waypoint_id' => $value['source_waypoint_id'], 'header' => $value['header']]);
    }

    public function editorData(SurveyBatch $batch): array
    {
        $batch->load(['feeder.gridStation', 'feeder.division', 'feeder.subDivision', 'surveyTeam', 'sources', 'waypoints',
            'transformers.entryRows', 'transformers.sections.consumers', 'transformers.pvRecords']);
        $feeder = $batch->feeder;

        return ['batch_id' => $batch->id, 'revision' => $batch->revision, 'status' => $batch->status,
            'staged_workflow' => $batch->staged_workflow, 'survey_status' => $batch->survey_status,
            'pdf_rows' => PdfRow::whereIn('source_file_id', $batch->sources->pluck('id'))->orderBy('page')->orderBy('row_number')->get()->toArray(),
            'pdf_lines' => PdfLine::whereIn('source_file_id', $batch->sources->pluck('id'))->orderBy('id')->get()->toArray(),
            'defaults' => ['feeder_identifier' => $feeder?->feeder_code, 'feeder_name' => $feeder?->feeder_name,
                'substation_identifier' => $feeder?->gridStation?->code, 'substation_name' => $feeder?->gridStation?->name,
                'division' => $feeder?->division?->name, 'subdivision' => $feeder?->subDivision?->name,
                'subdivision_code' => $feeder?->subDivision?->code, 'survey_date' => $batch->survey_date->format('Y-m-d'),
                'team_group' => $batch->surveyTeam?->code],
            'sources' => $batch->sources->map(fn ($source) => $source->only(['id', 'kind', 'original_name', 'status', 'version', 'metadata']))->values()->all(),
            'registered_transformers' => $feeder?->transformers()->orderBy('transformer_code')->get(['id', 'transformer_code', 'capacity_kva'])
                ->map(fn ($transformer) => ['id' => $transformer->id, 'code' => $transformer->transformer_code, 'capacity_kva' => $transformer->capacity_kva])->all() ?? [],
            'entry_settings' => app(SurveyWaypoint::class)->settings($batch->project_id),
            'waypoints' => $batch->waypoints->map(fn ($point) => $point->only(['id', 'name', 'source_file_id']) + ['recorded_at' => $point->recorded_at?->utc()->toIso8601String()])->values()->all(),
            'transformers' => $batch->transformers->map(fn ($transformer) => app(SurveyWaypoint::class)->transformerLocation([
                'id' => $transformer->id, 'code' => $transformer->code, 'capacity_kva' => $transformer->capacity_kva,
                'source_waypoint_id' => $transformer->source_waypoint_id, 'header' => $transformer->header,
                'rows' => $transformer->entryRows->map->surveyData()->values()->all(),
                'legacy_sections' => $transformer->sections->filter(fn ($section) => ! ($section->original_entry['entry_rows_managed'] ?? false))->values()->toArray(),
                'pv_records' => $transformer->pvRecords->toArray(),
            ], $batch->waypoints->toArray()))->values()->all(), 'lookups' => app(EntryLookups::class)->options($batch->project_id), 'issues' => $this->issues($batch)];
    }

    public function normalized(array $data): array
    {
        $conductors = [];
        foreach (['R', 'Y', 'B', 'N'] as $phase) {
            $value = $data['conductors'][$phase] ?? null;
            $conductors[$phase] = $value === '__none__' || $value === '' ? '' : ($value === null ? null : trim($value));
        }
        $consumers = [];
        foreach (array_keys(EntryLookups::CONSUMERS) as $category) {
            $value = $data['consumers'][$category] ?? null;
            $consumers[$category] = $value === null || $value === '' ? null : (int) $value;
        }
        if (array_key_exists('consumer_phases', $data)) {
            $phases = [];
            foreach (EntryLookups::SINGLE_PHASE_CONSUMERS as $category) {
                if (! array_key_exists($category, $data['consumer_phases'])) {
                    continue;
                }
                $phases[$category] = [];
                foreach (['R', 'Y', 'B'] as $phase) {
                    $value = $data['consumer_phases'][$category][$phase] ?? null;
                    $phases[$category][$phase] = $value === null || $value === '' ? null : (int) $value;
                }
                $entered = array_filter($phases[$category], fn ($value) => $value !== null);
                // With no phase allocation, retain the explicitly entered total.
                $consumers[$category] = $entered === [] ? $consumers[$category] : array_sum($entered);
                if ($consumers[$category] > 1000000) {
                    throw ValidationException::withMessages(['consumer_phases.'.$category => strtoupper($category).' total cannot exceed 1,000,000.']);
                }
            }
            $data['consumer_phases'] = $phases;
        }
        $data['conductors'] = $conductors;
        $data['pair_number'] = (int) $data['pair_number'];
        foreach (['gpx_source_id', 'source_pdf_id', 'source_page'] as $field) {
            $data[$field] = empty($data[$field]) ? null : (int) $data[$field];
        }
        $data['consumers'] = $consumers;
        $data['intersection'] = (bool) $data['intersection'];
        $data['manually_verified'] = (bool) ($data['manually_verified'] ?? false);
        $data['pv_details'] = array_values($data['pv_details'] ?? []);
        if (($consumers['pv'] ?? 0) <= 0) {
            $data['pv_details'] = array_values(array_filter($data['pv_details'], fn ($pv) => $this->hasPvObservation($pv)));
        }
        $data['inheritance'] = $data['inheritance'] ?? [];
        unset($data['revision'], $data['save_uuid'], $data['client_uuid'], $data['pdf_progress_row_id']);

        return $data;
    }

    private function hasPvObservation(array $pv): bool
    {
        foreach (['reference', 'meter_number', 'service_load_kw', 'installed_capacity_kw', 'remarks'] as $key) {
            if (trim((string) ($pv[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Build only the explicitly entered S/E pair; never sort waypoint references. */
    public function synchronizePair(NetworkTransformer $transformer, int $pairNumber): ?NetworkSection
    {
        $rows = $transformer->entryRows()->where('pair_number', $pairNumber)->get();
        $section = NetworkSection::where('transformer_id', $transformer->id)
            ->where('original_entry->entry_rows_managed', true)
            ->where('original_entry->entry_pair_number', $pairNumber)->first();
        if ($rows->isEmpty()) {
            if ($section) {
                $section->pvRecords()->update(['section_id' => null]);
                $section->consumers()->delete();
                $section->delete();
            }

            return null;
        }
        $start = $rows->firstWhere('designation', 'S');
        $end = $rows->firstWhere('designation', 'E');
        $phase = EntryLookups::phase($start?->conductors ?? []);
        $conductors = array_map(EntryLookups::libraryCode(...), $start?->conductors ?? array_fill_keys(['R', 'Y', 'B', 'N'], null));
        $phases = $phase['phases'];
        if (! empty($conductors['N'])) {
            $phases[] = 'N';
        }
        $original = array_replace($section?->original_entry ?? [], ['entry_rows_managed' => true, 'entry_pair_number' => $pairNumber,
            'entry_rows' => $rows->map->surveyData()->values()->all(),
            'manually_verified' => $start && $end && $start->manually_verified && $end->manually_verified,
            'phase_entries_complete' => $phase['complete'] && ($start?->conductors['N'] ?? null) !== null]);
        foreach (['start' => $start, 'end' => $end] as $endpoint => $entry) {
            if ($entry?->composite_identifier) {
                $original[$endpoint.'_survey_identifier'] = $entry->composite_identifier;
                $original[$endpoint.'_row_date'] = $entry->row_date?->format('Y-m-d');
            } else {
                unset($original[$endpoint.'_survey_identifier'], $original[$endpoint.'_row_date']);
            }
        }
        $firstPair = $transformer->entryRows()->min('pair_number');
        if ($pairNumber == $firstPair && $start) {
            $original['start_coordinate_source'] = app(StartingPoleCoordinates::class)->registered(['header' => $transformer->header])
                ? StartingPoleCoordinates::REGISTERED : SurveyWaypoint::FIRST_PAIR_COORDINATE_SOURCE;
        } else {
            unset($original['start_coordinate_source']);
        }
        $values = ['start_reference' => $start?->waypoint_reference, 'end_reference' => $end?->waypoint_reference,
            'start_source_file_id' => $start?->gpx_source_id, 'end_source_file_id' => $end?->gpx_source_id,
            'start_waypoint_id' => null, 'end_waypoint_id' => null, 'phases' => $phases, 'conductors' => $conductors,
            'equipment_type' => $start?->equipment_type, 'pole_class' => $start?->pole_class, 'pole_height' => $start?->pole_height,
            'pole_height_unit' => $start?->pole_height_unit, 'length_approved_by' => null,
            'source_pdf_id' => $start?->source_pdf_id ?? $end?->source_pdf_id,
            'source_page' => $start?->source_page ?? $end?->source_page,
            'source_row' => mb_substr('Pair '.$pairNumber.' ('.($start?->source_row ?? 'S').'/'.($end?->source_row ?? 'E').')', 0, 60), 'original_entry' => $original];
        $section = $section ? tap($section)->update($values) : $transformer->sections()->create($values);
        $rows->each(fn ($row) => $row->updateQuietly(['section_id' => $section->id]));
        $section->updateQuietly(['original_entry' => array_replace($section->original_entry, ['entry_rows' => $rows->map->surveyData()->values()->all()])]);
        foreach (array_diff(array_keys(EntryLookups::CONSUMERS), ['pv']) as $category) {
            $entered = $rows->pluck('consumers.'.$category)->filter(fn ($value) => $value !== null);
            $count = $entered->isEmpty() ? null : $entered->sum();
            $consumer = $section->consumers()->firstOrNew(['category' => $category]);
            $demand = $consumer->count === $count ? $consumer->demand : null;
            $consumer->fill(['count' => $count, 'original_value' => json_encode($rows->mapWithKeys(fn ($row) => [$row->designation => $row->consumers[$category] ?? null])), 'demand' => $demand])->save();
        }
        foreach ($rows as $row) {
            $row->pvRecords()->update(['section_id' => $section->id]);
        }

        return $section;
    }

    public function synchronizePv(EntryRow $row): void
    {
        // Row PV records are distinct from any existing transformer-level records.
        $row->pvRecords()->delete();
        foreach ($row->pv_details ?? [] as $pv) {
            $row->pvRecords()->create(['transformer_id' => $row->transformer_id, 'section_id' => $row->section_id,
                'reference' => $pv['reference'] ?? '', 'installed_capacity_kw' => $pv['installed_capacity_kw'] ?? null,
                'service_load_kw' => $pv['service_load_kw'] ?? null, 'meter_number' => $pv['meter_number'] ?? null, 'remarks' => $pv['remarks'] ?? null,
                'original_entry' => ['entry_row_id' => $row->id, 'source_pdf_id' => $row->source_pdf_id, 'source_page' => $row->source_page,
                    'source_row' => $row->source_row, 'manually_verified' => $row->manually_verified]]);
        }
    }

    public function issues(SurveyBatch|array $input): array
    {
        if ($input instanceof SurveyBatch) {
            $input->loadMissing(['transformers.entryRows', 'waypoints', 'sources']);
            $data = ['transformers' => $input->transformers->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'header' => $t->header, 'capacity_kva' => $t->capacity_kva,
                'source_waypoint_id' => $t->source_waypoint_id, 'section_count' => $t->sections()->count(), 'entry_rows' => $t->entryRows->map->surveyData()->all()])->all(),
                'waypoints' => $input->waypoints->toArray(), 'sources' => $input->sources->toArray(), 'entry_settings' => app(SurveyWaypoint::class)->settings($input->project_id),
                'configuration' => Configuration::where('project_id', $input->project_id)->first()?->toArray()];
        } else {
            $data = $input;
        }
        $simple = GenerationMode::simple($data['configuration'] ?? null);
        $issues = [];
        $add = function (string $code, string $message, object $row) use (&$issues): void {
            $issues[] = ['code' => $code, 'message' => $message, 'entry_row_id' => $row->id, 'transformer_id' => $row->transformer_id,
                'section_id' => $row->section_id, 'source_pdf_id' => $row->source_pdf_id, 'source_page' => $row->source_page, 'source_row' => $row->source_row];
        };
        foreach ($data['transformers'] ?? [] as $transformerData) {
            $transformerData = app(SurveyWaypoint::class)->transformerLocation($transformerData, $data['waypoints'] ?? []);
            $transformer = (object) $transformerData;
            if (! empty($transformerData['entry_rows']) || ! empty($transformerData['header']['operator_entry'])) {
                $headerIssue = function (string $code, string $message) use (&$issues, $transformer): void {
                    $issues[] = ['code' => $code, 'message' => $message, 'transformer_id' => $transformer->id,
                        'entry_row_id' => null, 'section_id' => null, 'source_pdf_id' => null, 'source_page' => null, 'source_row' => null];
                };
                foreach (['substation_name', 'feeder_name', 'feeder_identifier', 'division', 'subdivision', 'subdivision_code', 'make', 'inspector', 'location', 'survey_date', 'mounting', 'service_category'] as $key) {
                    if ($simple && in_array($key, ['make', 'mounting', 'service_category'], true)) {
                        continue;
                    }
                    if (trim((string) ($transformerData['header'][$key] ?? '')) === '') {
                        $headerIssue('incomplete_operator_header', 'Transformer header: enter '.str_replace('_', ' ', $key).'.');
                    }
                }
                if ((! $simple || ($transformerData['capacity_kva'] ?? null) !== null) && (! is_numeric($transformerData['capacity_kva'] ?? null) || $transformerData['capacity_kva'] <= 0)) {
                    $headerIssue('missing_operator_capacity', 'Transformer header: enter positive capacity in kVA.');
                }
                if (empty($transformerData['source_waypoint_id'])) {
                    $headerIssue('missing_operator_root', 'The first S/E pair needs its first E row with one matching GPX point and valid latitude, longitude and any recorded elevation. Upload or select its GPX file. First S uses captured transformer coordinates when available, otherwise this E point.');
                }
                if (empty($transformerData['entry_rows']) && ($transformerData['section_count'] ?? count($transformerData['sections'] ?? [])) === 0) {
                    $headerIssue('missing_operator_rows', 'Enter at least one complete S/E pair for this transformer.');
                }
            }
            foreach (app(EntryTopology::class)->issues($transformerData['entry_rows'] ?? []) as $topologyIssue) {
                $add($topologyIssue['code'], $topologyIssue['message'], (object) $topologyIssue['row']);
            }
            $firstPair = collect($transformerData['entry_rows'] ?? [])->min('pair_number');
            foreach (collect($transformerData['entry_rows'] ?? [])->map(fn ($row) => (object) $row)->groupBy('pair_number') as $number => $rows) {
                if ($rows->count() !== 2 || $rows->pluck('designation')->sort()->values()->all() !== ['E', 'S']) {
                    $add('incomplete_entry_pair', 'Pair '.$number.' needs both an S row and an E row.', $rows->first());
                }
                $start = $rows->firstWhere('designation', 'S');
                $end = $rows->firstWhere('designation', 'E');
                $conductorsDiffer = $start && $end && $start->conductors !== $end->conductors;
                if ($conductorsDiffer && $simple) {
                    // null is unrecorded; an empty string explicitly records an absent conductor.
                    $conductorsDiffer = collect(['R', 'Y', 'B', 'N'])->contains(function ($phase) use ($start, $end) {
                        $from = $start->conductors[$phase] ?? null;
                        $to = $end->conductors[$phase] ?? null;

                        return $from !== null && $to !== null && $from !== $to;
                    });
                }
                if ($conductorsDiffer) {
                    $add('pair_conductors_differ', 'Pair '.$number.': S and E conductor selections differ. Confirm the section conductors or explicitly copy the S values.', $end);
                }
                foreach ($rows as $row) {
                    $label = 'Pair '.$number.' '.$row->designation.': ';
                    $matchContext = (array) $row;
                    if (empty($row->composite_identifier)) {
                        $matchContext['row_date'] = null;
                    }
                    $matches = collect(app(SurveyWaypoint::class)->matches($matchContext, $data['waypoints'] ?? []));
                    if (($row->identity_version ?? null) === 2 && empty($row->composite_identifier)) {
                        $add('incomplete_survey_identifier', $label.'enter two group digits, a valid row date and three GPS digits.', $row);
                    }
                    if (($row->identity_version ?? null) === 2) {
                        try {
                            $expected = app(SurveyWaypoint::class)->compose($row->group_number, $row->row_date, $row->waypoint_reference,
                                $data['entry_settings']['two_digit_year_start'] ?? $data['configuration']['settings']['entry']['two_digit_year_start'] ?? SurveyWaypoint::DEFAULT_YEAR_START);
                            if ($expected !== ($row->composite_identifier ?? null)) {
                                $add('invalid_survey_identifier', $label.'complete waypoint differs from its recorded components.', $row);
                            }
                        } catch (ValidationException $exception) {
                            $add('invalid_survey_identifier', $label.implode(' ', array_merge(...array_values($exception->errors()))), $row);
                        }
                    }
                    $firstStart = $number == $firstPair && $row->designation === 'S';
                    $firstEnd = $number == $firstPair && $row->designation === 'E';
                    if ($matches->count() !== 1 && $firstEnd) {
                        $add('missing_first_e_coordinates', $label.'the required first E GPX point is missing or ambiguous. Upload or select the matching GPX file; first E must always have its own coordinate evidence.', $row);
                    }
                    if ($matches->count() !== 1 && ! $firstStart) {
                        $namedPoints = collect($data['waypoints'] ?? [])->filter(fn ($point) => (string) $point['name'] === (string) $row->waypoint_reference);
                        if ($matches->isEmpty() && $namedPoints->isNotEmpty()) {
                            $add('entry_waypoint_source_mismatch', $label.'GPS '.$row->waypoint_reference.' exists in another GPX source. Select the correct GPX file in the row editor.', $row);
                        } else {
                            $add($matches->isEmpty() ? 'entry_missing_waypoint' : 'entry_ambiguous_waypoint', $label.($matches->isEmpty() ? 'GPS '.$row->waypoint_reference.' is missing from the uploaded GPX files.' : 'GPS '.$row->waypoint_reference.' has multiple matches; choose its GPX source.'), $row);
                        }
                    }
                    if (! $firstStart && $matches->count() === 1 && ! app(SurveyWaypoint::class)->validCoordinates($matches->first())) {
                        $add($firstEnd ? 'invalid_first_e_coordinates' : 'invalid_entry_coordinates', $label.($firstEnd ? 'the required first E GPX point' : 'the selected GPX point').' has invalid latitude, longitude or recorded elevation. Correct the GPX source before verification or generation.', $row);
                    }
                    $phase = EntryLookups::phase($row->conductors);
                    if (! $simple && (! $phase['complete'] || ($row->conductors['N'] ?? null) === null)) {
                        $add('incomplete_entry_conductors', $label.'each conductor needs a code or explicit None / Not Present.', $row);
                    } elseif (! $simple && $phase['display'] === '') {
                        $add('entry_no_active_phase', $label.'at least one R/Y/B conductor is required.', $row);
                    }
                    $missingEquipment = [];
                    if (! $simple && ! $row->equipment_type) {
                        $missingEquipment[] = 'equipment type';
                    }
                    if (! $simple && ! $row->pole_class) {
                        $missingEquipment[] = 'pole class';
                    }
                    $hasHeight = $row->pole_height !== null && $row->pole_height !== '';
                    if ((! $simple || $hasHeight) && (! is_numeric($row->pole_height) || $row->pole_height <= 0)) {
                        $missingEquipment[] = 'positive pole/tower height';
                    }
                    if ($missingEquipment) {
                        $add('incomplete_entry_equipment', $label.'enter '.implode(', ', $missingEquipment).'.', $row);
                    }
                    if ((! $simple || $hasHeight || ! empty($row->pole_height_unit)) && ! in_array($row->pole_height_unit, ['m', 'ft'], true)) {
                        $add('missing_entry_height_unit', $label.'pole/tower height needs an explicit feet (ft) or metres (m) unit.', $row);
                    }
                    if (! $row->group_number || ! $row->row_date) {
                        $add('incomplete_entry_date_group', $label.'group/team and date need entry.', $row);
                    }
                    $pdf = collect($data['sources'] ?? [])->firstWhere('id', $row->source_pdf_id);
                    if (! $pdf || $pdf['kind'] !== 'pdf' || ! $row->source_page || $row->source_page < 1 || ! $row->source_row || $row->source_page > ($pdf['metadata']['page_count'] ?? 0)) {
                        $add('invalid_entry_pdf_source', $label.'select a valid source PDF, page and survey row.', $row);
                    } elseif (! collect($transformerData['header']['pages'] ?? [])->contains(fn ($page) =>
                        (string) ($page['source_pdf_id'] ?? '') === (string) $row->source_pdf_id
                        && (int) ($page['page'] ?? 0) === (int) $row->source_page
                        && in_array($page['confirmed'] ?? false, [true, 1, '1'], true)
                        && in_array($page['role'] ?? '', ['header', 'continuation'], true))) {
                        $add('unassociated_entry_page', $label.'PDF page '.$row->source_page.' is not confirmed as belonging to transformer '.($transformerData['code'] ?? $transformer->id).'. Open this PDF page and click Continue Current Transformer, or select the correct transformer before submitting.', $row);
                    }
                    if (($row->consumers['pv'] ?? 0) > 0 && empty($row->pv_details)) {
                        $add('missing_entry_pv_details', $label.'enter details for the recorded PV consumers.', $row);
                    }
                    foreach ($row->pv_details ?? [] as $pv) {
                        if (($row->consumers['pv'] ?? 0) <= 0 && ! $this->hasPvObservation($pv)) {
                            continue;
                        }
                        if (trim((string) ($pv['reference'] ?? '')) === '' && trim((string) ($pv['meter_number'] ?? '')) === '') {
                            $add('incomplete_entry_pv_details', $label.'Enter either Consumer No. or Meter No. for the PV consumer. PV service load and solar capacity are optional.', $row);
                        }
                    }
                }
            }
        }

        return $issues;
    }

    public function assertComplete(SurveyBatch $batch): void
    {
        $issues = $this->issues($batch);
        if ($issues) {
            throw ValidationException::withMessages(['rows' => array_column($issues, 'message')]);
        }
    }
}
