<?php

namespace App\Services\Mdb;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Optional observations describing existing consumers, never additional consumer counts. */
class ServiceCableService
{
    public function rules(): array
    {
        return [
            'entry_row_id' => 'required|integer', 'category' => 'required|in:rs,rl,sc,lc,si,li,pb,ag,st',
            'consumer_number' => 'required|integer|min:1|max:1000000',
            'group_number' => ['required', 'string', 'regex:/^\d{2}$/D'], 'row_date' => 'required|date_format:Y-m-d',
            'waypoint_reference' => ['required', 'string', 'regex:/^\d{3}$/D'],
            'gpx_source_id' => 'required|integer', 'meter_waypoint_id' => 'required|integer',
            'consumer_reference' => 'nullable|string|max:255', 'meter_number' => 'nullable|string|max:255',
            'phase' => 'nullable|in:R,Y,B,RYB', 'conductor_code' => 'nullable|string|max:255',
            'measured_length_m' => 'nullable|numeric|gt:0|max:100000',
            'length_evidence' => 'required_with:measured_length_m|nullable|string|max:4000',
            'source_pdf_id' => 'required|integer', 'source_page' => 'required|integer|min:1|max:10000',
            'source_row' => 'nullable|string|max:255', 'remarks' => 'nullable|string|max:4000',
        ];
    }

    public function issues(array $transformer, array $points, array $sources = []): array
    {
        $issues = $slots = $meters = [];
        foreach ($transformer['service_cables'] ?? [] as $cable) {
            $label = 'Service cable '.($cable['composite_identifier'] ?? '?').': ';
            $add = function (string $message) use (&$issues, $transformer, $cable, $label): void {
                $issues[] = ['code' => 'invalid_service_cable', 'message' => $label.$message,
                    'transformer_id' => $transformer['id'], 'entry_row_id' => $cable['entry_row_id'] ?? null];
            };
            $validator = Validator::make($cable, $this->rules());
            if ($validator->fails()) {
                $add($validator->errors()->first());
                continue;
            }
            if (empty($transformer['service_cables_enabled'])) {
                $add('Meter waypoint entry is disabled for this transformer.');
            }
            $row = collect($transformer['entry_rows'] ?? [])->firstWhere('id', $cable['entry_row_id']);
            if (! $row || ($row['designation'] ?? '') !== 'E') {
                $add('Select an E pole belonging to this transformer.');
                continue;
            }
            $count = $row['consumers'][$cable['category']] ?? null;
            if ($count === null || $cable['consumer_number'] > $count) {
                $add('Consumer number exceeds the recorded '.strtoupper($cable['category']).' count at this E pole.');
            }
            $slot = $row['id'].':'.$cable['category'].':'.$cable['consumer_number'];
            if (isset($slots[$slot])) {
                $add('This consumer already has a service cable.');
            }
            $slots[$slot] = true;
            try {
                $identity = app(SurveyWaypoint::class)->compose($cable['group_number'], $cable['row_date'], $cable['waypoint_reference']);
                if ($identity !== ($cable['composite_identifier'] ?? null)) {
                    $add('Meter identity differs from its group/date/waypoint components.');
                }
                if (isset($meters[$identity]) || collect($transformer['entry_rows'] ?? [])->contains('composite_identifier', $identity)) {
                    $add('The meter identity is already used by a meter or pole.');
                }
                $meters[$identity] = true;
                $meter = $this->point($cable, $points);
                $poleMatches = app(SurveyWaypoint::class)->matches($row, $points);
                if (count($poleMatches) === 1 && $poleMatches[0]['id'] === $meter['id']) {
                    $add('The meter endpoint must differ from its starting E pole.');
                }
                if (count($poleMatches) === 1 && empty($cable['measured_length_m'])
                    && $poleMatches[0]['latitude'] == $meter['latitude'] && $poleMatches[0]['longitude'] == $meter['longitude']) {
                    $add('The meter and pole have identical coordinates; record a measured cable length or correct the waypoint.');
                }
            } catch (ValidationException $exception) {
                $add($exception->validator?->errors()->first() ?: $exception->getMessage());
            }
            $start = collect($transformer['entry_rows'] ?? [])->first(fn ($entry) => $entry['pair_number'] == $row['pair_number'] && $entry['designation'] === 'S');
            if (empty($row['composite_identifier']) || empty($start['composite_identifier']) || empty($row['section_id'])) {
                $add('Complete and save both S/E identities before recording meter connections.');
            }
            if (isset($transformer['sections']) && collect($transformer['sections'])->where('id', $row['section_id'] ?? null)->count() !== 1) {
                $add('The starting E pole has no unique distribution section.');
            }
            $available = EntryLookups::phase($start['conductors'] ?? [])['phases'];
            $phase = $cable['phase'] ?? null;
            if ($phase) {
                $single = in_array($cable['category'], EntryLookups::SINGLE_PHASE_CONSUMERS, true);
                if (($single && strlen($phase) !== 1) || (! $single && $phase !== 'RYB') || array_diff(str_split($phase), $available)) {
                    $add('Recorded meter phase does not match its consumer category or available pole phases.');
                }
            }
            if ($sources) {
                $pdf = collect($sources)->firstWhere('id', $cable['source_pdf_id']);
                $gpx = collect($sources)->firstWhere('id', $cable['gpx_source_id']);
                if (! $pdf || $pdf['kind'] !== 'pdf' || $pdf['status'] !== 'ready' || $cable['source_page'] > ($pdf['metadata']['page_count'] ?? 0)) {
                    $add('The recorded PDF/page is unavailable.');
                }
                if (! $gpx || $gpx['kind'] !== 'gpx' || $gpx['status'] !== 'ready') {
                    $add('The recorded GPX source is unavailable.');
                }
            }
        }

        // Reconcile recorded single-phase customers without assigning phases to unrecorded meters.
        foreach (collect($transformer['service_cables'] ?? [])->groupBy(fn ($c) => ($c['entry_row_id'] ?? '').':'.($c['category'] ?? '')) as $cables) {
            $cable = $cables->first();
            if (! in_array($cable['category'] ?? '', EntryLookups::SINGLE_PHASE_CONSUMERS, true)) continue;
            $row = collect($transformer['entry_rows'] ?? [])->firstWhere('id', $cable['entry_row_id'] ?? null);
            $recorded = app(RecordedConsumerPhases::class)->fromRows($row ? [$row] : [], $cable['category']);
            foreach (['R', 'Y', 'B'] as $phase) {
                if ($recorded !== null && $cables->where('phase', $phase)->count() > ($recorded[$phase] ?? 0)) {
                    $issues[] = ['code' => 'invalid_service_cable', 'message' => 'Recorded '.$phase.' meter connections exceed the E pole phase count.',
                        'transformer_id' => $transformer['id'], 'entry_row_id' => $cable['entry_row_id']];
                }
            }
        }

        return $issues;
    }

    public function assertValid(array $transformer, array $points, array $sources = []): void
    {
        $issues = $this->issues($transformer, $points, $sources);
        if ($issues) throw ValidationException::withMessages(['service_cables' => array_column($issues, 'message')]);
    }

    public function point(array $cable, array $points): array
    {
        $matches = app(SurveyWaypoint::class)->matches($cable, $points);
        $selected = collect($matches)->firstWhere('id', $cable['meter_waypoint_id']);
        if (! $selected || ! app(SurveyWaypoint::class)->validCoordinates($selected)) {
            throw ValidationException::withMessages(['meter_waypoint_id' => 'Select a valid GPX meter waypoint matching its reference and source.']);
        }

        return $selected;
    }

    public function forConnection(array $section, array $connection): ?array
    {
        return collect($section['service_cables'] ?? [])->first(fn ($cable) => ($connection['kind'] ?? '') === 'consumer'
            && $cable['category'] === $connection['category'] && $cable['consumer_number'] == ($connection['consumer_number'] ?? null));
    }

    public function attachSections(array $transformer): array
    {
        foreach ($transformer['sections'] ?? [] as $i => $section) {
            $endIds = collect($transformer['entry_rows'] ?? [])->where('section_id', $section['id'])->where('designation', 'E')->pluck('id')->all();
            $cables = array_values(array_filter($transformer['service_cables'] ?? [], fn ($c) => in_array($c['entry_row_id'], $endIds)));
            unset($section['service_cables']);
            if ($cables) $section['service_cables'] = $cables;
            $transformer['sections'][$i] = $section;
        }

        return $transformer;
    }

    public function allocatePhases(array $allocated, ?array $recorded, string $category, array $section): array
    {
        $cables = collect($section['service_cables'] ?? [])->where('category', $category);
        if ($cables->isEmpty()) return $allocated;
        $reserved = [];
        $remaining = $recorded;
        foreach ($cables as $cable) {
            if (empty($cable['phase'])) continue;
            $index = (int) $cable['consumer_number'] - 1;
            $reserved[$index] = $cable['phase'];
            if ($remaining !== null) {
                $remaining[$cable['phase']]--;
                if ($remaining[$cable['phase']] < 0) throw ValidationException::withMessages(['service_cables' => 'Meter phase connections exceed the recorded E phase counts.']);
            }
        }
        $pool = [];
        if ($remaining !== null) {
            foreach ($remaining as $phase => $count) array_push($pool, ...array_fill(0, $count, $phase));
        }
        foreach ($allocated as $index => $phase) $allocated[$index] = $reserved[$index] ?? ($remaining !== null ? array_shift($pool) : $phase);

        return $allocated;
    }

    public function length(array $cable, array $pole, array $meter): float
    {
        if (($cable['measured_length_m'] ?? null) !== null) return (float) $cable['measured_length_m'];
        foreach (['pole', 'meter'] as $name) {
            $point = $$name;
            if (! empty($point['correction'])) {
                if (empty($point['correction_approved_by']) || empty($point['correction_approved_at'])) {
                    throw ValidationException::withMessages(['service_cables' => 'Coordinate corrections require approval.']);
                }
                $$name = array_replace($point, $point['correction']);
            }
        }
        $projection = app(ProjectionService::class);
        $a = $projection->project($pole['latitude'], $pole['longitude'], 32643);
        $b = $projection->project($meter['latitude'], $meter['longitude'], 32643);

        return hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
    }
}
