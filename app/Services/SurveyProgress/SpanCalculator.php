<?php

namespace App\Services\SurveyProgress;

class SpanCalculator
{
    public function horizontal(array $a, array $b): float
    {
        // WGS84 inverse geodesic (Vincenty); never substitute a spherical distance on failure.
        $major = 6378137.0;
        $flattening = 1 / 298.257223563;
        $minor = $major * (1 - $flattening);
        $u1 = atan((1 - $flattening) * tan(deg2rad($a['lat'])));
        $u2 = atan((1 - $flattening) * tan(deg2rad($b['lat'])));
        $sinU1 = sin($u1);
        $cosU1 = cos($u1);
        $sinU2 = sin($u2);
        $cosU2 = cos($u2);
        $longitude = deg2rad($b['lon'] - $a['lon']);
        $longitude = atan2(sin($longitude), cos($longitude));
        $lambda = $longitude;
        for ($iteration = 0; $iteration < 200; $iteration++) {
            $sinLambda = sin($lambda);
            $cosLambda = cos($lambda);
            $sinSigma = hypot($cosU2 * $sinLambda, $cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda);
            $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLambda;
            if ($sinSigma < 1e-15) {
                if ($cosSigma > 0) {
                    return 0.0;
                }
                throw new \RuntimeException('Antipodal coordinates require geodesic review.');
            }
            $sigma = atan2($sinSigma, $cosSigma);
            $sinAlpha = $cosU1 * $cosU2 * $sinLambda / $sinSigma;
            $cosSquaredAlpha = max(0, 1 - $sinAlpha ** 2);
            $cos2Sigma = $cosSquaredAlpha > 1e-15 ? $cosSigma - 2 * $sinU1 * $sinU2 / $cosSquaredAlpha : 0;
            $c = $flattening / 16 * $cosSquaredAlpha * (4 + $flattening * (4 - 3 * $cosSquaredAlpha));
            $next = $longitude + (1 - $c) * $flattening * $sinAlpha * ($sigma + $c * $sinSigma * ($cos2Sigma + $c * $cosSigma * (-1 + 2 * $cos2Sigma ** 2)));
            if (abs($next - $lambda) < 1e-12) {
                $uSquared = $cosSquaredAlpha * ($major ** 2 - $minor ** 2) / $minor ** 2;
                $coefficientA = 1 + $uSquared / 16384 * (4096 + $uSquared * (-768 + $uSquared * (320 - 175 * $uSquared)));
                $coefficientB = $uSquared / 1024 * (256 + $uSquared * (-128 + $uSquared * (74 - 47 * $uSquared)));
                $deltaSigma = $coefficientB * $sinSigma * ($cos2Sigma + $coefficientB / 4 * ($cosSigma * (-1 + 2 * $cos2Sigma ** 2) - $coefficientB / 6 * $cos2Sigma * (-3 + 4 * $sinSigma ** 2) * (-3 + 4 * $cos2Sigma ** 2)));

                return $minor * $coefficientA * ($sigma - $deltaSigma);
            }
            $lambda = $next;
        }
        throw new \RuntimeException('WGS84 distance did not converge; this span requires coordinate review.');
    }

    public function calculate(array $surveys, array $historical): array
    {
        $confirmed = 0.0;
        $unverified = 0.0;
        $unresolved = 0;
        $issues = [];
        $spans = [];
        foreach ($surveys as $survey) {
            $pdf = $survey['pdf'];
            $points = $survey['gpx']['points'];
            foreach (array_merge($pdf['issues'], $survey['gpx']['issues']) as $issue) {
                $issues[] = $issue + ['survey' => $survey['key']];
            }
            $root = count($pdf['roots']) === 1 ? $pdf['roots'][0] : null;
            $rootValid = $root && count($historical[$root] ?? []) === 1;
            $graph = [];
            foreach ($pdf['spans'] as $span) {
                $graph[$span['start']][] = $span['end'];
                $graph[$span['end']][] = $span['start'];
            }
            $reachable = [];
            $pending = $root ? [$root] : [];
            while ($pending) {
                $point = array_pop($pending);
                if (isset($reachable[$point])) {
                    continue;
                } $reachable[$point] = true;
                foreach ($graph[$point] ?? [] as $next) {
                    $pending[] = $next;
                }
            }
            if (! $rootValid) {
                $issues[] = ['code' => 'missing_or_ambiguous_kmz_root', 'survey' => $survey['key'], 'reference' => $root];
            }
            foreach ($pdf['spans'] as $span) {
                $ids = [$span['start'], $span['end']];
                sort($ids, SORT_STRING);
                $key = implode(':', $ids);
                $reasons = [];
                if ($span['start'] === $span['end']) {
                    $reasons[] = 'self_span';
                }
                if (! $rootValid) {
                    $reasons[] = 'unverified_transformer_root';
                }
                if (! isset($reachable[$span['start']])) {
                    $reasons[] = 'disconnected_branch';
                }
                // A partially parsed PDF never produces a confirmed subtotal.
                if ($pdf['issues']) {
                    $reasons[] = 'incomplete_pdf_extraction';
                }
                $resolved = [];
                foreach (['start', 'end'] as $endpoint) {
                    $id = $span[$endpoint];
                    $candidates = $points[$id] ?? [];
                    if ($id === $root && $rootValid) {
                        $resolved[$endpoint] = $historical[$id][0];
                    } elseif (count($candidates) === 1) {
                        $resolved[$endpoint] = $candidates[0];
                    } else {
                        $reasons[] = count($candidates) > 1 ? 'duplicate_waypoint' : 'missing_waypoint';
                    }
                }
                if ($rootValid && count($points[$root] ?? []) === 1) {
                    try {
                        if ($this->horizontal($points[$root][0], $historical[$root][0]) > 20) {
                            $reasons[] = 'historical_transformer_coordinate_conflict';
                        }
                    } catch (\RuntimeException) {
                        $reasons[] = 'historical_transformer_coordinate_conflict';
                    }
                }
                $distance = null;
                $horizontal = null;
                if (count($resolved) === 2) {
                    try {
                        $horizontal = $this->horizontal($resolved['start'], $resolved['end']);
                    } catch (\RuntimeException) {
                        $reasons[] = 'geodesic_calculation_failed';
                    }
                    if ($resolved['start']['elevation'] === null || $resolved['end']['elevation'] === null) {
                        $reasons[] = 'missing_elevation';
                    } elseif ($horizontal !== null) {
                        $distance = hypot($horizontal, $resolved['end']['elevation'] - $resolved['start']['elevation']);
                    }
                }
                $record = $span + ['key' => $key, 'survey' => $survey['key'], 'confirmed' => ! $reasons && $distance !== null, 'distance_m' => $distance, 'horizontal_m' => $horizontal, 'reasons' => array_values(array_unique($reasons)), 'coordinates' => $resolved];
                if (isset($spans[$key])) {
                    $existing = $spans[$key];
                    $existingCoordinates = [];
                    $currentCoordinates = [];
                    foreach ($existing['coordinates'] as $point) {
                        $existingCoordinates[$point['id']] = $point;
                    }
                    foreach ($resolved as $point) {
                        $currentCoordinates[$point['id']] = $point;
                    }
                    if ($existingCoordinates != $currentCoordinates) {
                        $spans[$key]['confirmed'] = false;
                        $spans[$key]['reasons'][] = 'conflicting_duplicate_span';
                        $spans[$key]['horizontal_m'] = null;
                        $spans[$key]['distance_m'] = null;
                    } elseif (! $record['confirmed']) {
                        $spans[$key]['confirmed'] = false;
                        $spans[$key]['reasons'] = array_values(array_unique(array_merge($existing['reasons'], $record['reasons'])));
                    }
                    $issues[] = ['code' => 'duplicate_span_ignored', 'key' => $key, 'survey' => $survey['key']];
                } else {
                    $spans[$key] = $record;
                }
            }
        }
        foreach ($spans as $span) {
            if ($span['confirmed']) {
                $confirmed += $span['distance_m'];
            } else {
                $unverified += $span['horizontal_m'] ?? 0;
                $unresolved++;
                $issues[] = ['code' => 'unverified_span', 'key' => $span['key'], 'reasons' => $span['reasons']];
            }
        }

        return ['confirmed_km' => $confirmed / 1000, 'unverified_horizontal_km' => $unverified / 1000, 'unresolved_spans' => $unresolved, 'spans' => array_values($spans), 'issues' => $issues];
    }
}
