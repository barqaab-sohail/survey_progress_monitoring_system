<?php

namespace App\Services\Mdb;

use App\Models\Mdb\SurveyBatch;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class NetworkValidator
{
    public function __construct(private readonly ProjectionService $projection, private readonly SnapshotService $snapshots) {}

    /** Validation never changes handwritten entries or infers electrical connections. */
    public function validate(SurveyBatch|array $input): array
    {
        $data = $input instanceof SurveyBatch ? $this->snapshots->snapshot($input) : $input;
        $errors = [];
        $warnings = [];
        $errors = app(EntryService::class)->issues($data);
        $issue = static function (string $code, string $message, array $context = [], bool $warning = false) use (&$errors, &$warnings): void {
            $value = array_merge(['code' => $code, 'message' => $message], array_intersect_key($context, array_flip(['section_id', 'transformer_id', 'source_pdf_id', 'source_page', 'source_row', 'waypoint_id', 'source_file_id'])));
            if ($warning) {
                $warnings[] = $value;
            } else {
                $errors[] = $value;
            }
        };
        $config = is_array($data['configuration'] ?? null) ? $data['configuration'] : [];
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
        $epsg = $config['epsg'] ?? null;
        if (! ($config['approved_by'] ?? null) || ! ($config['approved_at'] ?? null)) {
            $issue('unapproved_settings', 'Project electrical settings and coordinate projection require engineering approval.');
        }
        if (! is_numeric($epsg) || (int) $epsg <= 0) {
            $issue('missing_projection', 'Configure an explicit projected EPSG code with metre units.');
        }
        foreach (['frequency_hz', 'nominal_voltage_kv'] as $key) {
            if (! is_numeric($settings[$key] ?? null) || (float) $settings[$key] <= 0) {
                $issue('missing_electrical_setting', 'Approved '.$key.' is required.');
            }
        }
        foreach (['conductor_library_revision', 'transformer_library_revision'] as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                $issue('missing_library_setting', 'Approved '.$key.' is required.');
            }
        }
        if (empty($config['load_assumptions'])) {
            $issue('missing_load_assumptions', 'Document and approve measured load inputs or a load estimation method; consumer counts do not establish demand.');
        }
        $mapping = is_array($settings['mapping'] ?? null) ? $settings['mapping'] : [];
        if (empty($mapping['version']) || empty($mapping['reviewed_by']) || empty($mapping['reviewed_at'])) {
            $issue('unapproved_mapping', 'A reviewed SynerGEE schema mapping is required.');
        }
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        if (! ($template['approved_by'] ?? null) || ! ($template['approved_at'] ?? null)) {
            $issue('missing_template', 'An approved clean SynerGEE template is required.');
        }
        $libraries = is_array($template['metadata']['equipment_references'] ?? null) ? $template['metadata']['equipment_references'] : [];
        foreach (['conductors', 'transformers', 'configurations'] as $library) {
            if (empty($libraries[$library]) || ! is_array($libraries[$library])) {
                $issue('missing_equipment_library', 'The approved template must identify its reviewed '.$library.' equipment references.');
                $libraries[$library] = [];
            }
        }
        if (! is_array($libraries['equipment'] ?? null)) {
            $libraries['equipment'] = [];
        }
        $sources = [];
        foreach ($data['sources'] ?? [] as $source) {
            $sources[(string) $source['id']] = $source;
            if (in_array($source['status'] ?? '', ['queued', 'pending', 'processing', 'failed'], true)) {
                $issue('source_not_ready', 'Source '.$source['original_name'].' has not completed processing.', ['source_file_id' => $source['id']]);
            }
            if (! preg_match('/^[a-f0-9]{64}$/i', $source['sha256'] ?? '')) {
                $issue('missing_source_hash', 'Original source integrity hash is missing.', ['source_file_id' => $source['id']]);
            }
        }
        if ($sources === []) {
            $issue('missing_sources', 'Upload and process PDF survey forms and GPX files.');
        }
        $points = [];
        $byName = [];
        $bySourceName = [];
        $effective = [];
        foreach ($data['waypoints'] ?? [] as $point) {
            $id = (string) $point['id'];
            $context = ['waypoint_id' => $point['id'], 'source_file_id' => $point['source_file_id'] ?? null];
            if (isset($points[$id])) {
                $issue('duplicate_waypoint_id', 'Waypoint ID '.$id.' occurs more than once.', $context);

                continue;
            }
            $points[$id] = $point;
            $byName[(string) $point['name']][] = $id;
            $scope = (string) ($point['source_file_id'] ?? '').'|'.(string) $point['name'];
            $bySourceName[$scope][] = $id;
            if (! isset($sources[(string) ($point['source_file_id'] ?? '')]) || ($sources[(string) ($point['source_file_id'] ?? '')]['kind'] ?? '') !== 'gpx') {
                $issue('wrong_waypoint_source', 'Waypoint must belong to an authorized GPX source in this batch.', $context);
            }
            if (isset($point['batch_id'], $data['batch']['id']) && $point['batch_id'] != $data['batch']['id']) {
                $issue('foreign_waypoint', 'Waypoint belongs to another batch.', $context);
            }
            $coordinates = $point;
            if (! empty($point['correction'])) {
                if (empty($point['correction_approved_by']) || empty($point['correction_approved_at']) || empty($point['correction']['reason'])) {
                    $issue('unapproved_coordinate_correction', 'Coordinate correction requires a reason and verifier approval.', $context);
                } else {
                    $coordinates = array_merge($point, $point['correction']);
                }
            }
            $lat = $coordinates['latitude'] ?? null;
            $lon = $coordinates['longitude'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lon) || ! is_finite((float) $lat) || ! is_finite((float) $lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $issue('invalid_coordinates', 'Waypoint '.$point['name'].' has missing or invalid WGS84 coordinates.', $context);
            } else {
                $effective[$id] = ['latitude' => (float) $lat, 'longitude' => (float) $lon];
            }
        }
        foreach ($bySourceName as $ids) {
            if (count($ids) > 1) {
                $unique = array_unique(array_map(fn ($id) => isset($effective[$id]) ? json_encode($effective[$id]) : 'invalid', $ids));
                $point = $points[$ids[0]];
                $dates = array_map(fn ($id) => empty($points[$id]['recorded_at']) ? null : Carbon::parse($points[$id]['recorded_at'])->timezone('Asia/Karachi')->format('Y-m-d'), $ids);
                $surveyRows = collect($data['transformers'] ?? [])->flatMap(fn ($t) => $t['entry_rows'] ?? []);
                $hasSurveyContext = $surveyRows->contains(fn ($row) => ! empty($row['composite_identifier']) && ($row['waypoint_reference'] ?? null) === $point['name']
                    && (empty($row['gpx_source_id']) || $row['gpx_source_id'] == $point['source_file_id']));
                if ($hasSurveyContext && ! in_array(null, $dates, true) && count(array_unique($dates)) === count($dates)) {
                    $issue('repeated_waypoint_across_dates', 'GPS name '.$point['name'].' repeats on distinct recording dates. Verify the complete survey identities and selected evidence.', ['source_file_id' => $point['source_file_id']], true);

                    continue;
                }
                $issue(count($unique) > 1 ? 'conflicting_coordinates' : 'duplicate_waypoint_name', 'Waypoint name '.$point['name'].' is duplicated in one GPX source'.(count($unique) > 1 ? ' with conflicting coordinates.' : '.'), ['source_file_id' => $point['source_file_id']], count($unique) === 1);
            }
        }
        $projected = [];
        if ($effective && is_numeric($epsg) && (int) $epsg > 0) {
            try {
                $values = $this->projection->projectMany(array_values($effective), (int) $epsg);
                $projected = array_combine(array_keys($effective), $values);
            } catch (\Throwable $exception) {
                $issue('projection_failed', $exception->getMessage());
            }
        }
        $topology = [];
        $transformerCodes = [];
        $sectionIds = [];
        $rawPoints = $points;
        $rawEffective = $effective;
        $rawProjected = $projected;
        foreach ($data['transformers'] ?? [] as $transformer) {
            $points = $rawPoints;
            $effective = $rawEffective;
            $projected = $rawProjected;
            $tid = $transformer['id'];
            $tc = ['transformer_id' => $tid];
            foreach ($transformer['entry_rows'] ?? [] as $entry) {
                if (empty($entry['manually_verified'])) {
                    $issue('unverified_entry_row', 'Check each S/E row against the PDF in Review & save.', $tc + ['section_id' => $entry['section_id'] ?? null, 'source_pdf_id' => $entry['source_pdf_id'] ?? null, 'source_page' => $entry['source_page'] ?? null, 'source_row' => $entry['source_row'] ?? null]);
                }
                if (! empty($entry['composite_identifier']) && ! empty($entry['gpx_source_id'])) {
                    $matches = app(SurveyWaypoint::class)->matches($entry, $data['waypoints'] ?? []);
                    $time = count($matches) === 1 ? ($matches[0]['recorded_at'] ?? null) : null;
                    if ($time && Carbon::parse($time)->timezone('Asia/Karachi')->format('Y-m-d') !== $entry['row_date']) {
                        $issue('gpx_survey_date_difference', 'GPX recording date differs from the PDF row date for '.$entry['composite_identifier'].'. Verify the selected source; the PDF date was retained.', $tc + ['source_file_id' => $entry['gpx_source_id']], true);
                    }
                }
            }
            if (isset($transformerCodes[(string) ($transformer['code'] ?? '')])) {
                $issue('duplicate_transformer_code', 'Transformer survey codes must be distinct within the batch.', $tc);
            }
            $transformerCodes[(string) ($transformer['code'] ?? '')] = true;
            if (empty($transformer['code'])) {
                $issue('missing_transformer_code', 'Transformer code is required.', $tc);
            }
            if (! is_numeric($transformer['capacity_kva'] ?? null) || (float) $transformer['capacity_kva'] <= 0) {
                $issue('missing_transformer_capacity', 'Transformer capacity must be verified in kVA.', $tc);
            }
            if (! in_array($transformer['header']['manually_verified'] ?? false, [true, 1, '1'], true)) {
                $issue('unverified_transformer_header', 'Manually verify the transformer header and its explicit continuation pages.', $tc);
            }
            foreach (['feeder_identifier', 'feeder_name', 'substation_identifier', 'substation_name', 'make', 'location', 'mounting', 'survey_date', 'team_group', 'inspector'] as $field) {
                if (! is_string($transformer['header'][$field] ?? null) || trim($transformer['header'][$field]) === '') {
                    $issue('incomplete_transformer_header', 'Verify the transformer header '.$field.' against its source PDF, or explicitly document an unresolved observation for engineering review.', $tc);
                }
            }
            $headerDate = $transformer['header']['survey_date'] ?? null;
            if ($headerDate !== null && $headerDate !== '') {
                $parsed = is_string($headerDate) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $headerDate, new \DateTimeZone('UTC')) : false;
                if (! $parsed || $parsed->format('Y-m-d') !== $headerDate) {
                    $issue('invalid_header_survey_date', 'Transformer survey date must be a verified calendar date in YYYY-MM-DD format.', $tc);
                }
            }
            $root = isset($transformer['source_waypoint_id']) ? (string) $transformer['source_waypoint_id'] : null;
            if (! $root || ! isset($points[$root], $effective[$root])) {
                $issue('missing_transformer_source', 'Select a verified transformer/source waypoint from this batch.', $tc);
            }
            try {
                $identityNetwork = app(SurveyWaypoint::class)->network($transformer, $points);
                $points = $identityNetwork['points'];
                $transformer['sections'] = $identityNetwork['sections'];
                $root = $identityNetwork['root'];
                foreach ($points as $key => $point) {
                    if (isset($point['gpx_waypoint_id'])) {
                        $raw = (string) $point['gpx_waypoint_id'];
                        if (isset($effective[$raw])) {
                            $effective[$key] = $effective[$raw];
                        }
                        if (isset($projected[$raw])) {
                            $projected[$key] = $projected[$raw];
                        }
                    }
                }
            } catch (ValidationException $exception) {
                $issue('conflicting_survey_identity', implode(' ', array_merge(...array_values($exception->errors()))), $tc);
            }
            $networkMapping = $mapping['networks'][(string) ($transformer['code'] ?? '')] ?? [];
            if (! is_array($networkMapping)) {
                $networkMapping = [];
            }
            if (empty($networkMapping['source_nodes']) || empty($networkMapping['source_sections']) || empty($networkMapping['transformer_fields'])) {
                $issue('missing_source_mapping', 'Review source supply nodes, feeder/transformer connection and transformer equipment fields.', $tc);
            } elseif (! in_array($networkMapping['transformer_fields']['TransformerType'] ?? '', $libraries['transformers'] ?? [], true)) {
                $issue('unknown_transformer_equipment', 'Transformer equipment type is absent from the approved template library.', $tc);
            }
            $pageAssociations = [];
            $hasHeaderSource = false;
            foreach ($transformer['header']['pages'] ?? [] as $page) {
                $pc = $tc + ['source_pdf_id' => $page['source_pdf_id'] ?? null, 'source_page' => $page['page'] ?? null];
                $pdf = $sources[(string) ($page['source_pdf_id'] ?? '')] ?? [];
                if (($pdf['kind'] ?? '') !== 'pdf' || ! in_array($page['confirmed'] ?? false, [true, 1, '1'], true) || ! is_numeric($page['page'] ?? null) || $page['page'] < 1 || (isset($pdf['metadata']['page_count']) && $page['page'] > $pdf['metadata']['page_count'])) {
                    $issue('invalid_page_association', 'Transformer continuation pages must be explicitly confirmed and within an authorized source PDF.', $pc);
                } elseif (in_array($page['role'] ?? '', ['header', 'continuation', 'pv'], true)) {
                    $pageAssociations[(string) $page['source_pdf_id'].':'.(string) $page['page']][] = $page['role'];
                    $hasHeaderSource = $hasHeaderSource || $page['role'] === 'header';
                } else {
                    $issue('invalid_page_role', 'Explicitly classify this transformer page as header, continuation or PV.', $pc);
                }
            }
            if (! $hasHeaderSource) {
                $issue('missing_header_provenance', 'Explicitly associate and confirm the source PDF header page for this transformer.', $tc);
            }
            $adjacency = [];
            $edges = [];
            $pairs = [];
            $parent = [];
            $find = function (string $node) use (&$parent, &$find): string {
                $parent[$node] ??= $node;
                if ($parent[$node] !== $node) {
                    $parent[$node] = $find($parent[$node]);
                }

                return $parent[$node];
            };
            foreach ($transformer['sections'] ?? [] as $section) {
                $sid = $section['id'];
                $sc = array_merge($tc, ['section_id' => $sid], array_intersect_key($section, array_flip(['source_pdf_id', 'source_page', 'source_row'])));
                if (isset($sectionIds[(string) $sid])) {
                    $issue('duplicate_section_id', 'Section ID occurs more than once.', $sc);
                }
                $sectionIds[(string) $sid] = true;
                if (! in_array($section['original_entry']['manually_verified'] ?? false, [true, 1, '1'], true)) {
                    $issue('unverified_survey_row', 'Manually verify this source PDF row before approval.', $sc);
                }
                $pdf = $sources[(string) ($section['source_pdf_id'] ?? '')] ?? null;
                if (! $pdf || ($pdf['kind'] ?? '') !== 'pdf' || empty($section['source_page']) || empty($section['source_row'])) {
                    $issue('missing_pdf_provenance', 'Select a source PDF, page and survey row.', $sc);
                } elseif ($section['source_page'] < 1 || (isset($pdf['metadata']['page_count']) && $section['source_page'] > $pdf['metadata']['page_count'])) {
                    $issue('invalid_pdf_page', 'Survey page lies outside the uploaded PDF.', $sc);
                } elseif (! array_intersect($pageAssociations[(string) $section['source_pdf_id'].':'.(string) $section['source_page']] ?? [], ['header', 'continuation'])) {
                    $issue('unassociated_section_page', 'Explicitly confirm that this PDF page shares the selected transformer header before verifying its survey rows.', $sc);
                }
                $start = $this->resolveEndpoint('start', $section, $points, $byName, $bySourceName, $issue, $sc);
                $end = $this->resolveEndpoint('end', $section, $points, $byName, $bySourceName, $issue, $sc);
                $this->validateElectrical($section, $libraries, $issue, $sc);
                if (! $start || ! $end) {
                    continue;
                }
                if (! isset($effective[$start], $effective[$end])) {
                    $issue('missing_section_coordinates', 'Both section endpoints need valid approved coordinates.', $sc);

                    continue;
                }
                if ($start === $end) {
                    $issue('self_connected_section', 'A section cannot connect a waypoint to itself.', $sc);

                    continue;
                }
                $pair = [$start, $end];
                sort($pair, SORT_STRING);
                $pairKey = implode(':', $pair);
                if (isset($pairs[$pairKey])) {
                    $issue('duplicate_section', 'The same endpoint pair is repeated in this transformer network.', $sc);
                }
                $pairs[$pairKey] = true;
                $a = $find($start);
                $b = $find($end);
                if ($a === $b) {
                    $issue('cycle_detected', 'This connection creates a network cycle; review the intended electrical operation.', $sc, ! empty($settings['allow_cycles']) && ! empty($config['approved_by']));
                } else {
                    $parent[$a] = $b;
                }
                $adjacency[$start][] = $end;
                $adjacency[$end][] = $start;
                $estimate = null;
                if (isset($projected[$start], $projected[$end])) {
                    if (! empty($section['geometry'])) {
                        try {
                            $estimate = $this->projection->length([$effective[$start], ...$section['geometry'], $effective[$end]], (int) $epsg);
                        } catch (\Throwable $exception) {
                            $issue('invalid_geometry', $exception->getMessage(), $sc);
                        }
                    } else {
                        $estimate = hypot($projected[$start]['x'] - $projected[$end]['x'], $projected[$start]['y'] - $projected[$end]['y']);
                    }
                }
                $measured = $section['measured_length_m'] ?? null;
                if ($measured !== null && (! is_numeric($measured) || $measured <= 0 || empty($section['measured_length_reason']) || empty($section['length_approved_by']))) {
                    $issue('unapproved_measured_length', 'Measured length overrides require positive metres, provenance/reason and verifier approval.', $sc);
                }
                $length = $measured !== null ? (float) $measured : $estimate;
                if ($length !== null && ($length < 0.5 || $length > (float) ($settings['review_length_above_m'] ?? 2000))) {
                    $issue('suspicious_length', 'Section length is unusual and requires engineering review.', $sc, true);
                }
                $edges[] = ['section_id' => $sid, 'id' => $this->stableId('S', $data, $tid, $sid), 'from' => $this->stableId('N', $data, $tid, $start), 'to' => $this->stableId('N', $data, $tid, $end), 'start_waypoint_id' => $points[$start]['id'], 'end_waypoint_id' => $points[$end]['id'], 'estimated_length_m' => $estimate, 'length_m' => $length, 'length_kind' => $measured !== null ? 'approved_measured' : (! empty($section['geometry']) ? 'geometry_estimate' : 'endpoint_estimate')];
            }
            if (! $edges) {
                $issue('empty_network', 'Enter verified section endpoint pairs for this transformer.', $tc);
            }
            $seen = [];
            $queue = $root ? [$root] : [];
            while ($queue) {
                $node = array_pop($queue);
                if (isset($seen[$node])) {
                    continue;
                } $seen[$node] = true;
                foreach ($adjacency[$node] ?? [] as $next) {
                    if (! isset($seen[$next])) {
                        $queue[] = $next;
                    }
                }
            }
            foreach (array_keys($adjacency) as $node) {
                if (! isset($seen[$node])) {
                    $issue('disconnected_network', 'Waypoint '.$points[$node]['name'].' is disconnected from the transformer/source.', $tc + ['waypoint_id' => $points[$node]['id']]);
                }
            }
            $nodes = [];
            foreach (array_keys($adjacency) as $node) {
                $nodes[] = ['id' => $this->stableId('N', $data, $tid, $node), 'waypoint_id' => $points[$node]['id'], 'reference' => $points[$node]['name'], 'source_file_id' => $points[$node]['source_file_id'], 'coordinates' => $effective[$node], 'projected' => $projected[$node] ?? null];
            }
            $topology[] = ['transformer_id' => $tid, 'source_waypoint_id' => $root ? ($points[$root]['id'] ?? null) : null, 'nodes' => $nodes, 'sections' => $edges];
            foreach ($transformer['pv_records'] ?? [] as $pv) {
                $pc = $tc + array_intersect_key($pv['original_entry'] ?? [], array_flip(['source_pdf_id', 'source_page', 'source_row']));
                if (empty($pv['reference']) || ! is_numeric($pv['installed_capacity_kw'] ?? null) || $pv['installed_capacity_kw'] < 0) {
                    $issue('incomplete_pv_record', 'PV reference and verified installed capacity in kW are required.', $pc);
                }
                if (! in_array($pv['original_entry']['manually_verified'] ?? false, [true, 1, '1'], true)) {
                    $issue('unverified_pv_record', 'Manually verify PV observations against their original PDF row.', $pc);
                }
                $pdf = $sources[(string) ($pv['original_entry']['source_pdf_id'] ?? '')] ?? [];
                $page = $pv['original_entry']['source_page'] ?? null;
                if (($pdf['kind'] ?? '') !== 'pdf' || ! is_numeric($page) || $page < 1 || empty($pv['original_entry']['source_row']) || (isset($pdf['metadata']['page_count']) && $page > $pdf['metadata']['page_count'])) {
                    $issue('missing_pv_provenance', 'PV observations require an authorized source PDF, page and row.', $pc);
                }
                if (! empty($pv['section_id']) && ! in_array($pv['section_id'], array_column($transformer['sections'] ?? [], 'id'))) {
                    $issue('foreign_pv_section', 'PV associated section must belong to this transformer.', $pc);
                }
                if (($pv['installed_capacity_kw'] ?? 0) > 0) {
                    $exclusion = $mapping['pv_exclusion'] ?? [];
                    if (empty($exclusion['reason']) || empty($exclusion['reviewed_at']) || empty($exclusion['reviewed_by']) || $exclusion['reviewed_by'] != ($config['approved_by'] ?? null)) {
                        $issue('pv_model_unmapped', 'Positive PV capacity needs a supported reviewed SynerGEE generation mapping or an explicit engineering-approved exclusion with reason.', $pc);
                    }
                }
            }
        }
        if (empty($data['transformers'])) {
            $issue('missing_transformers', 'Enter at least one transformer network.');
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'topology' => $topology];
    }

    private function resolveEndpoint(string $end, array $section, array $points, array $byName, array $bySourceName, callable $issue, array $context): ?string
    {
        $id = $section[$end.'_waypoint_id'] ?? null;
        $ref = $section[$end.'_reference'] ?? null;
        $source = $section[$end.'_source_file_id'] ?? null;
        if ($id !== null) {
            $point = $points[(string) $id] ?? null;
            if (! $point || ($source && ! isset($point['survey_identifier']) && $point['source_file_id'] != $source) || ($ref !== null && (string) $point['name'] !== (string) $ref)) {
                $issue('invalid_waypoint_selection', ucfirst($end).' waypoint does not match this batch/source and original text reference.', $context);

                return null;
            }

            return (string) $id;
        }
        if ($ref === null || trim((string) $ref) === '') {
            $issue('missing_waypoint_reference', ucfirst($end).' waypoint reference is required.', $context);

            return null;
        }
        $matches = $source ? ($bySourceName[(string) $source.'|'.(string) $ref] ?? []) : ($byName[(string) $ref] ?? []);
        if (count($matches) !== 1) {
            $issue(count($matches) === 0 ? 'missing_waypoint' : 'ambiguous_waypoint', 'Waypoint '.(string) $ref.(count($matches) === 0 ? ' is absent from the selected GPX source/batch.' : ' has multiple matches; select the source file and exact verified point.'), $context);

            return null;
        }

        return $matches[0];
    }

    private function validateElectrical(array $section, array $libraries, callable $issue, array $context): void
    {
        $phases = $section['phases'] ?? [];
        if (! is_array($phases) || $phases === [] || count($phases) !== count(array_unique($phases)) || array_diff($phases, ['R', 'Y', 'B', 'N']) || ! array_intersect($phases, ['R', 'Y', 'B'])) {
            $issue('invalid_phases', 'Select distinct R, Y, B phases and neutral where present.', $context);
            $phases = [];
        }
        $conductors = $section['conductors'] ?? [];
        foreach (['R', 'Y', 'B', 'N'] as $phase) {
            $code = $conductors[$phase] ?? $conductors[$phase === 'N' ? 'neutral' : strtolower($phase)] ?? null;
            if (in_array($phase, $phases, true) && (! is_string($code) || trim($code) === '')) {
                $issue('missing_conductor', 'Active '.$phase.' requires a separately verified conductor code.', $context);
            }
            if ($code && ! in_array($phase, $phases, true)) {
                $issue('phase_conductor_mismatch', $phase.' conductor is provided without that phase.', $context);
            }
            if ($code && ! in_array($code, $libraries['conductors'] ?? [], true)) {
                $issue('unknown_conductor', 'Conductor '.$code.' is absent from the approved equipment library.', $context);
            }
        }
        if (empty($section['equipment_type']) || empty($section['equipment_ref'])) {
            $issue('missing_equipment', 'Verified equipment type and library reference are required.', $context);
        } elseif (! in_array($section['equipment_ref'], array_merge($libraries['configurations'] ?? [], $libraries['equipment'] ?? []), true)) {
            $issue('unknown_equipment', 'Equipment reference is absent from the approved template library.', $context);
        }
        if (empty($section['pole_class']) || ! is_numeric($section['pole_height'] ?? null) || $section['pole_height'] <= 0 || ! in_array($section['pole_height_unit'] ?? '', ['m', 'ft'], true)) {
            $issue('missing_pole_units', 'Verify pole class and positive pole height with explicit m or ft units.', $context);
        }
        $categories = array_column($section['consumers'] ?? [], 'category');
        foreach (['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'] as $category) {
            if (! in_array($category, $categories, true)) {
                $issue('missing_consumer_category', 'Verify the '.$category.' count printed on the survey form; explicitly record zero where confirmed.', $context);
            }
        }
        foreach ($section['consumers'] ?? [] as $consumer) {
            if (! in_array($consumer['category'] ?? '', ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'], true)) {
                $issue('invalid_consumer_category', 'Consumer category is not one of the nine printed categories. Int is an intersection flag.', $context);
            }
            $count = $consumer['count'] ?? null;
            if ($count === null || filter_var($count, FILTER_VALIDATE_INT) === false || $count < 0) {
                $issue('unverified_consumer_count', 'Consumer category '.$consumer['category'].' needs a verified nonnegative count; blank does not mean zero.', $context);

                continue;
            }
            if ($count === 0 || (string) $count === '0') {
                continue;
            }
            $demand = $consumer['demand'] ?? [];
            if (empty($demand['method']) || empty($demand['evidence']) || empty($demand['approved_by']) || empty($demand['phase_values'])) {
                $issue('unapproved_demand', 'Consumer counts require measured or approved documented demand and explicit phase allocation.', $context);

                continue;
            }
            foreach (array_intersect($phases, ['R', 'Y', 'B']) as $activePhase) {
                if (! isset($demand['phase_values'][$activePhase])) {
                    $issue('missing_phase_demand', 'Explicit measured or approved demand values are required for active '.$activePhase.' phase.', $context);
                }
            }
            $total = 0;
            foreach ($demand['phase_values'] as $phase => $values) {
                if (! in_array($phase, ['R', 'Y', 'B'], true) || (! in_array($phase, $phases, true) && array_sum(array_map('floatval', $values)) != 0)) {
                    $issue('invalid_load_phase', 'Demand phase allocation must use active phases; a disconnected phase can only have explicit verified zeros.', $context);
                }
                foreach (['customers', 'kw', 'kvar', 'kva'] as $key) {
                    if (! is_numeric($values[$key] ?? null) || ! is_finite((float) $values[$key]) || ($key !== 'kvar' && $values[$key] < 0)) {
                        $issue('incomplete_demand', 'Approved per-phase '.$key.' is required; no load values are inferred.', $context);
                    }
                }
                if (filter_var($values['customers'] ?? null, FILTER_VALIDATE_INT) === false) {
                    $issue('invalid_phase_customers', 'Per-phase customer counts must be integers.', $context);
                }
                $total += (float) ($values['customers'] ?? 0);
                if (is_numeric($values['kw'] ?? null) && is_numeric($values['kvar'] ?? null) && is_numeric($values['kva'] ?? null) && abs(hypot((float) $values['kw'], (float) $values['kvar']) - (float) $values['kva']) > max(0.01, (float) $values['kva'] * 0.01)) {
                    $issue('inconsistent_demand', 'Approved kW, kvar and kVA are inconsistent and require engineering review.', $context);
                }
            }
            if ($total !== (float) $count) {
                $issue('customer_allocation_mismatch', 'Per-phase customer allocation must equal the separately recorded consumer count.', $context);
            }
        }
    }

    private function stableId(string $prefix, array $data, int|string $transformer, int|string $record): string
    {
        return $prefix.substr(hash('sha256', implode(':', [$data['batch']['project_id'] ?? '', $data['batch']['id'] ?? '', $transformer, $record])), 0, 30);
    }
}
