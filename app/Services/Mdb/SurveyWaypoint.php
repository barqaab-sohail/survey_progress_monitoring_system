<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Survey identity is independent of the short GPX name and recording timestamp. */
class SurveyWaypoint
{
    public const DEFAULT_YEAR_START = 2000;

    /** The first pair's E row is the surveyed GPX transformer connection. */
    public function transformerLocation(array $transformer, array $points): array
    {
        $rows = collect($transformer['entry_rows'] ?? $transformer['rows'] ?? []);
        if ($rows->isEmpty() && ($transformer['header']['location_source'] ?? null) !== 'first_e_gpx') {
            return $transformer;
        }
        $firstPair = $rows->min('pair_number');
        $end = $rows->first(fn ($row) => $row['pair_number'] == $firstPair && $row['designation'] === 'E');
        $context = $end ?? [];
        if (empty($context['composite_identifier'])) {
            $context['row_date'] = null;
        }
        $matches = $end ? $this->matches($context, $points) : [];
        $point = count($matches) === 1 ? $matches[0] : null;
        if ($point && (! is_numeric($point['latitude'] ?? null) || ! is_numeric($point['longitude'] ?? null)
            || ! is_finite((float) $point['latitude']) || ! is_finite((float) $point['longitude'])
            || abs((float) $point['latitude']) > 90 || abs((float) $point['longitude']) > 180)) {
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
        $matches = array_values(array_filter($points, fn ($p) => ($row['waypoint_reference'] ?? null) !== null && (string) $p['name'] === (string) $row['waypoint_reference']
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
        $aliases = [];
        $rootCandidates = [];
        $sections = $transformer['sections'] ?? [];
        foreach ($sections as &$section) {
            foreach (['start', 'end'] as $end) {
                $identity = $section['original_entry'][$end.'_survey_identifier'] ?? null;
                $raw = (string) ($section[$end.'_waypoint_id'] ?? '');
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

        return ['points' => $points, 'sections' => $sections, 'root' => $root, 'aliases' => $aliases];
    }
}
