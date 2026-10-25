<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Survey identity is independent of the short GPX name and recording timestamp. */
class SurveyWaypoint
{
    public const DEFAULT_YEAR_START = 2000;

    public const FIRST_PAIR_COORDINATE_SOURCE = 'first_e_gpx';

    public function firstPair(array $transformer): array
    {
        $rows = collect($transformer['entry_rows'] ?? $transformer['rows'] ?? []);
        $number = $rows->min('pair_number');
        $pair = $rows->where('pair_number', $number);

        return ['number' => $number,
            'start' => $pair->where('designation', 'S')->count() === 1 ? $pair->firstWhere('designation', 'S') : null,
            'end' => $pair->where('designation', 'E')->count() === 1 ? $pair->firstWhere('designation', 'E') : null];
    }

    public function validCoordinates(array $point): bool
    {
        if (! empty($point['correction']) && ! empty($point['correction_approved_by'])
            && ! empty($point['correction_approved_at']) && ! empty($point['correction']['reason'])) {
            $point = array_replace($point, $point['correction']);
        }
        $latitude = $point['latitude'] ?? null;
        $longitude = $point['longitude'] ?? null;
        $elevation = $point['elevation'] ?? null;

        return is_numeric($latitude) && is_numeric($longitude)
            && is_finite((float) $latitude) && is_finite((float) $longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180
            && ($elevation === null || (is_numeric($elevation) && is_finite((float) $elevation)));
    }

    private function isFirstPairSection(array $section, array $pair): bool
    {
        if (! $pair['start'] || ! $pair['end']) {
            return false;
        }

        return isset($pair['start']['section_id'], $pair['end']['section_id'])
            ? $pair['start']['section_id'] == $section['id'] && $pair['end']['section_id'] == $section['id']
            : ! empty($section['original_entry']['entry_rows_managed']) && ($section['original_entry']['entry_pair_number'] ?? null) == $pair['number'];
    }

    /** Apply the frozen starting-coordinate choice only within the first entered S/E pair. */
    public function firstPairCoordinates(array $transformer, array $points): array
    {
        $pair = $this->firstPair($transformer);
        if ($pair['number'] === null) {
            return $transformer;
        }
        $transformer = $this->transformerLocation($transformer, $points);
        if (! $pair['start'] || ! $pair['end']) {
            return $transformer;
        }
        $point = collect($points)->firstWhere('id', $transformer['source_waypoint_id']);
        $registered = app(StartingPoleCoordinates::class)->registered($transformer);
        foreach ($transformer['sections'] ?? [] as $index => $section) {
            if (! $this->isFirstPairSection($section, $pair)) {
                continue;
            }
            $section['start_waypoint_id'] = $registered ? null : ($point['id'] ?? null);
            $section['end_waypoint_id'] = $point['id'] ?? null;
            // S has no original GPX observation; retain its handwritten reference.
            $section['start_source_file_id'] = null;
            $section['end_source_file_id'] = $point['source_file_id'] ?? $pair['end']['gpx_source_id'] ?? null;
            $section['original_entry']['start_coordinate_source'] = $registered ? StartingPoleCoordinates::REGISTERED : self::FIRST_PAIR_COORDINATE_SOURCE;
            $section['original_entry']['coordinate_entry_row_id'] = $registered ? ($pair['start']['id'] ?? null) : ($pair['end']['id'] ?? null);
            $transformer['sections'][$index] = $section;
        }

        return $transformer;
    }

    /** The first pair's E row is the surveyed GPX transformer connection. */
    public function transformerLocation(array $transformer, array $points): array
    {
        $rows = collect($transformer['entry_rows'] ?? $transformer['rows'] ?? []);
        if ($rows->isEmpty() && ($transformer['header']['location_source'] ?? null) !== 'first_e_gpx') {
            return $transformer;
        }
        $end = $this->firstPair($transformer)['end'];
        $context = $end ?? [];
        if (empty($context['composite_identifier'])) {
            $context['row_date'] = null;
        }
        $matches = $end ? $this->matches($context, $points) : [];
        $point = count($matches) === 1 ? $matches[0] : null;
        if ($point && ! $this->validCoordinates($point)) {
            $point = null;
        }
        $transformer['source_waypoint_id'] = $point['id'] ?? null;
        $transformer['header']['location_source'] = 'first_e_gpx';
        $transformer['header']['location_entry_row_id'] = $end['id'] ?? null;
        unset($transformer['header']['source_survey_identifier']);
        if ($point && ! empty($end['composite_identifier'])) {
            $transformer['header']['source_survey_identifier'] = $end['composite_identifier'];
        }

        return $transformer;
    }

    public function settings(int $projectId): array
    {
        $entry = Configuration::where('project_id', $projectId)->value('settings')['entry'] ?? [];

        return ['two_digit_year_start' => $entry['two_digit_year_start'] ?? self::DEFAULT_YEAR_START,
            'pole_height_unit' => $entry['pole_height_unit'] ?? config('mdb_workflow.entry.pole_height_unit')];
    }

    public function compose(?string $group, ?string $date, ?string $gps, ?int $yearStart = null): ?string
    {
        if (! $group || ! $date || ! $gps) {
            return null;
        }
        if (! preg_match('/^\d{2}$/D', $group) || ! preg_match('/^\d{3}$/D', $gps)) {
            throw ValidationException::withMessages(['waypoint_reference' => 'Use exactly two group digits and three GPS digits, retaining zeros.']);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['row_date' => 'Enter a valid calendar date.']);
        }
        $year = (int) $parsed->format('Y');
        if ($yearStart !== null && ($year < $yearStart || $year >= $yearStart + 100)) {
            throw ValidationException::withMessages(['row_date' => 'Date is outside the configured two-digit-year window.']);
        }

        return $group.$parsed->format('dmy').$gps;
    }

    public function parse(string $identifier, ?int $yearStart): array
    {
        if (! preg_match('/^\d{11}$/D', $identifier)) {
            throw ValidationException::withMessages(['composite_identifier' => 'The complete waypoint must contain exactly 11 digits.']);
        }
        $yearStart ??= self::DEFAULT_YEAR_START;
        $year = intdiv($yearStart, 100) * 100 + (int) substr($identifier, 6, 2);
        if ($year < $yearStart) {
            $year += 100;
        }
        $date = sprintf('%04d-%s-%s', $year, substr($identifier, 4, 2), substr($identifier, 2, 2));
        $result = ['group_number' => substr($identifier, 0, 2), 'row_date' => $date, 'waypoint_reference' => substr($identifier, 8, 3)];
        $this->compose($result['group_number'], $date, $result['waypoint_reference'], $yearStart);

        return $result;
    }

    public function matches(array $row, array $points): array
    {
        $matches = array_values(array_filter($points, fn ($p) => ! empty($p['source_file_id']) && empty($p['generation_input_id']) && empty($p['gpx_waypoint_id'])
            && ($row['waypoint_reference'] ?? null) !== null && (string) $p['name'] === (string) $row['waypoint_reference']
            && (empty($row['gpx_source_id']) || $p['source_file_id'] == $row['gpx_source_id'])));
        // Match the GPS number first. Recording dates only disambiguate repeated
        // names; a unique point remains valid when the PDF date differs.
        if (! empty($row['row_date'])) {
            $dated = array_values(array_filter($matches, function ($point) use ($row) {
                $time = $point['recorded_at'] ?? null;

                return $time && Carbon::parse($time)->timezone('Asia/Karachi')->format('Y-m-d') === $row['row_date'];
            }));
            if ($dated && count($matches) > 1) {
                $matches = $dated;

            }
        }

        return $matches;
    }

    /** Overlay identity nodes on a frozen network without rewriting any legacy IDs. */
    public function network(array $transformer, array $points): array
    {
        $transformer = $this->firstPairCoordinates($transformer, $points);
        $firstPair = $this->firstPair($transformer);
        if ($firstPair['number'] !== null && empty($transformer['source_waypoint_id'])) {
            throw ValidationException::withMessages(['waypoints' => 'The first S/E pair requires one matching first E GPX point with valid latitude, longitude and any recorded elevation. Upload or select the correct GPX; a later E point cannot replace it.']);
        }
        $aliases = [];
        $rootCandidates = [];
        $sections = $transformer['sections'] ?? [];
        $registered = app(StartingPoleCoordinates::class)->registered($transformer);
        $registeredRoot = null;
        foreach ($sections as &$section) {
            foreach (['start', 'end'] as $end) {
                $identity = $section['original_entry'][$end.'_survey_identifier'] ?? null;
                $raw = (string) ($section[$end.'_waypoint_id'] ?? '');
                if ($end === 'start' && $this->isFirstPairSection($section, $firstPair) && ($registered || isset($points[$raw]))) {
                    // Coordinate equality must not collapse the two S/E observations into a self-loop
                    // or overwrite a later pole that happens to have the same short name.
                    $key = 'first-s:'.$transformer['id'].':'.$section['id'];
                    $points[$key] = $registered
                        ? ['id' => $key, 'name' => $section['start_reference'], 'source_file_id' => null,
                            'latitude' => $registered['latitude'], 'longitude' => $registered['longitude'], 'elevation' => $registered['elevation'] ?? null,
                            'registered_transformer_id' => $registered['registered_transformer_id'],
                            'survey_identifier' => $identity ?? $section['start_reference'], 'coordinate_source' => StartingPoleCoordinates::REGISTERED]
                        : array_replace($points[$raw], ['id' => $key, 'name' => $section['start_reference'],
                            'gpx_waypoint_id' => $points[$raw]['id'], 'survey_identifier' => $identity ?? $section['start_reference'],
                            'coordinate_source' => self::FIRST_PAIR_COORDINATE_SOURCE]);
                    if ($registered) {
                        $registeredRoot = $key;
                    }
                    $section['start_waypoint_id'] = $key;

                    continue;
                }
                if (! $identity || ! isset($points[$raw])) {
                    continue;
                }
                $key = 'survey:'.$identity;
                $point = $points[$raw];
                $previousElevation = $points[$key]['elevation'] ?? null;
                $elevation = $point['elevation'] ?? null;
                $differentElevation = ($previousElevation === null) !== ($elevation === null) || $previousElevation != $elevation;
                if (isset($points[$key]) && ($points[$key]['latitude'] != $point['latitude'] || $points[$key]['longitude'] != $point['longitude'] || $differentElevation || ($points[$key]['correction'] ?? null) != ($point['correction'] ?? null))) {
                    throw ValidationException::withMessages(['waypoints' => 'The same complete survey identifier has conflicting coordinate evidence. Resolve its source.']);
                }
                $points[$key] = array_replace($point, ['id' => $key, 'gpx_waypoint_id' => $point['id'], 'survey_identifier' => $identity]);
                $section[$end.'_waypoint_id'] = $key;
                if ($raw === (string) ($transformer['source_waypoint_id'] ?? '')) {
                    $rootCandidates[$key] = true;
                }
            }
        }
        unset($section);
        $root = (string) ($transformer['source_waypoint_id'] ?? '');
        if ($rootCandidates) {
            $explicit = $transformer['header']['source_survey_identifier'] ?? null;
            $selected = $explicit ? 'survey:'.$explicit : (count($rootCandidates) === 1 ? array_key_first($rootCandidates) : null);
            if (! $selected || ! isset($rootCandidates[$selected])) {
                throw ValidationException::withMessages(['source_survey_identifier' => 'The transformer GPX point has multiple survey identities. Select its complete waypoint in the transformer header.']);
            }
            $aliases[$root] = $selected;
            $root = $selected;
        }
        // Model the transformer connection at S. Keep the E GPX evidence and all later poles intact.
        $root = $registeredRoot ?? $root;

        return ['points' => $points, 'sections' => $sections, 'root' => $root, 'aliases' => $aliases];
    }
}
