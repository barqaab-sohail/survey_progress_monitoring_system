<?php

namespace App\Services\Gpx;

use App\Services\SurveyProgress\SpanCalculator;
use Illuminate\Validation\ValidationException;

class Calculator
{
    public const VERSION = 'wgs84-parabola-1';

    public const DETAILS = 'Horizontal: WGS84 Vincenty inverse geodesic. Elevation: hypot(horizontal, end elevation - start elevation). Sag s is the maximum vertical drop below the straight support chord. With t in [0,1], z(t)=dz*t-4*s*t*(1-t); length=integral sqrt(H^2+(dz-4*s+8*s*t)^2) dt. The parabolic approximation assumes uniform vertical loading, shallow sag and a local flat span; it is not a catenary, tension, clearance, wind, ice or thermal design. Sag/H above 5% is flagged for engineering review. Without elevation, dz=0. GPX elevations are survey elevations, not attachment heights. No phase/conductor multiplier. Sag is attributes only in KMZ; exported lines join surveyed endpoints. GPX elevation datum is unverified; KML absolute assumes metres above mean sea level without datum conversion. Google Earth measurements may differ.';

    public function __construct(private SpanCalculator $geodesic) {}

    public function defaults(): array
    {
        return ['include_elevation' => false, 'include_sag' => false, 'default_sag' => null, 'sag_overrides' => [],
            'span_sequences' => '', 'connections_confirmed' => false, 'supports_confirmed' => false, 'missing_elevation' => null, 'long_span_m' => 500.0];
    }

    public function calculate(array $source, array $settings): array
    {
        $settings = array_replace($this->defaults(), $settings);
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $manual = trim($settings['span_sequences']) !== '';
        if ($settings['include_sag']) {
            if (! $manual || ! $settings['connections_confirmed'] || ! $settings['supports_confirmed']) {
                $fail('supports_confirmed', 'Sag requires explicit span sequences and confirmation that consecutive entries are actual support endpoints, not intermediate GPS samples.');
            }
            if (! is_numeric($settings['default_sag']) || ! is_finite((float) $settings['default_sag']) || $settings['default_sag'] < 0 || $settings['default_sag'] > 1000) {
                $fail('default_sag', 'Enter a default sag from 0 to 1,000 metres.');
            }
        }
        $chains = [];
        $groupNames = array_column($source['groups'], 'name', 'id');
        if ($manual) {
            foreach (preg_split('/\R/', trim($settings['span_sequences'])) as $i => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $ids = preg_split('/[\s,>]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
                if (count($ids) < 2) {
                    $fail('span_sequences', 'Each sequence needs at least two point IDs. Use a separate line for every branch.');
                }
                $last = null;
                foreach ($ids as $id) {
                    $point = $source['points'][$id] ?? null;
                    if (! $point) {
                        $fail('span_sequences', "Unknown point ID '$id'. Copy IDs from the points table.");
                    }
                    if ($last && ($last['group'] !== $point['group'] || ($point['kind'] !== 'waypoints' && $last['position'] >= $point['position']))) {
                        $fail('span_sequences', 'Keep each route/track segment separate and in its original order. Branches of standalone waypoints need separate lines.');
                    }
                    $last = $point;
                }
                $chains[] = ['id' => 'sequence'.($i + 1), 'name' => $groupNames[$last['group']].' / sequence '.($i + 1), 'points' => $ids];
            }
        } else {
            foreach ($source['groups'] as $group) {
                if ($group['kind'] !== 'waypoints') {
                    $chains[] = $group;
                }
            }
        }
        $warnings = $source['warnings'];
        $rows = $seen = [];
        $horizontal = $straight = $additional = $subtotal = 0.0;
        $included = $excluded = $blocked = 0;
        foreach ($chains as $chain) {
            for ($i = 1; $i < count($chain['points']); $i++) {
                if (count($rows) >= 10000) {
                    $fail('span_sequences', 'At most 10,000 spans are allowed.');
                }
                $a = $source['points'][$chain['points'][$i - 1]];
                $b = $source['points'][$chain['points'][$i]];
                $pair = [$a['id'], $b['id']];
                sort($pair, SORT_NATURAL);
                $key = implode('_', $pair);
                if (isset($seen[$key])) {
                    $fail('span_sequences', "Duplicate span {$a['id']} to {$b['id']}. Define each physical span only once, including shared branch sections.");
                }
                $seen[$key] = true;
                $row = ['id' => $key, 'group' => $chain['id'], 'group_name' => $chain['name'], 'start' => $a, 'end' => $b,
                    'horizontal_m' => null, 'elevation_difference_m' => $a['elevation'] !== null && $b['elevation'] !== null ? $b['elevation'] - $a['elevation'] : null,
                    'straight_m' => null, 'sag_m' => $settings['include_sag'] ? (float) ($settings['sag_overrides'][$key] ?? $settings['default_sag']) : 0.0,
                    'final_m' => null, 'status' => 'calculated', 'warnings' => []];
                if (! $a['valid'] || ! $b['valid']) {
                    $row['status'] = 'invalid_coordinates';
                } else {
                    try {
                        $row['horizontal_m'] = $this->geodesic->horizontal($a, $b);
                    } catch (\RuntimeException $exception) {
                        $row['status'] = 'geodesic_review_required';
                        $row['warnings'][] = $exception->getMessage();
                    }
                }
                $h = $row['horizontal_m'];
                if ($h !== null) {
                    $horizontal += $h;
                    if ($h > $settings['long_span_m']) {
                        $row['warnings'][] = 'Unusually long span; confirm connectivity and support endpoints.';
                    }
                    if ($h < 1e-6) {
                        $row['warnings'][] = 'Coincident horizontal coordinates; review duplicate points or vertical-only spans.';
                        if ($settings['include_sag'] && $row['sag_m'] > 0) {
                            $row['status'] = 'zero_horizontal_sag_undefined';
                        }
                    }
                }
                if ($row['status'] === 'calculated' && $manual && ! $settings['connections_confirmed']) {
                    $row['status'] = 'needs_connectivity_confirmation';
                }
                $dz = $settings['include_elevation'] ? $row['elevation_difference_m'] : 0.0;
                if ($row['status'] === 'calculated' && $dz === null) {
                    $row['status'] = match ($settings['missing_elevation']) {
                        'exclude' => 'excluded_missing_elevation',
                        'horizontal' => 'horizontal_fallback',
                        default => 'needs_elevation_choice',
                    };
                    if ($row['status'] === 'horizontal_fallback') {
                        $dz = 0.0;
                        $row['warnings'][] = 'Missing elevation: explicitly using horizontal distance (and sag if selected).';
                    }
                }
                if (in_array($row['status'], ['calculated', 'horizontal_fallback'], true)) {
                    $row['straight_m'] = hypot($h, $dz);
                    $row['final_m'] = $this->parabolicLength($h, $dz, $row['sag_m']);
                    if ($h > 0 && $row['sag_m'] / $h > 0.05) {
                        $row['warnings'][] = 'Sag exceeds 5% of horizontal span; shallow-sag approximation needs engineering review.';
                    }
                    $straight += $row['straight_m'];
                    $additional += $row['final_m'] - $row['straight_m'];
                    $subtotal += $row['final_m'];
                    $included++;
                } elseif ($row['status'] === 'excluded_missing_elevation') {
                    $excluded++;
                } else {
                    $blocked++;
                }
                foreach ($row['warnings'] as $warning) {
                    $warnings[] = "{$a['id']} → {$b['id']}: $warning";
                }
                if (! in_array($row['status'], ['calculated', 'horizontal_fallback'], true)) {
                    $warnings[] = "{$a['id']} → {$b['id']}: ".str_replace('_', ' ', $row['status']).'.';
                }
                $rows[] = $row;
            }
        }
        foreach ($settings['sag_overrides'] as $key => $sag) {
            if (! isset($seen[$key])) {
                $warnings[] = "Sag override $key is unused because this span is absent from the current sequences.";
            }
        }
        $connected = [];
        foreach ($rows as $row) {
            $connected[$row['start']['id']] = $connected[$row['end']['id']] = true;
        }
        $unconnected = count($source['points']) - count($connected);
        if ($unconnected > 0) {
            $warnings[] = "$unconnected points are not in any defined span and contribute no length.";
        }
        if (! $rows) {
            $warnings[] = 'No spans defined. Enter and confirm span sequences before calculating a total or exporting.';
        }
        if ($excluded) {
            $warnings[] = "$excluded spans explicitly excluded for missing elevation. The final total is for included spans only.";
        }
        $complete = $included > 0 && $blocked === 0;

        return ['version' => self::VERSION, 'label' => $settings['include_sag'] ? 'Estimated conductor length' : 'Surveyed route/span length',
            'point_count' => count($source['points']), 'span_count' => count($rows), 'valid_spans' => $included, 'excluded_spans' => $excluded, 'blocked_spans' => $blocked,
            'horizontal_m' => $horizontal, 'horizontal_complete' => count(array_filter($rows, fn ($r) => $r['horizontal_m'] === null)) === 0,
            'straight_m' => $straight, 'sag_additional_m' => $additional, 'subtotal_m' => $subtotal,
            'final_m' => $complete ? $subtotal : null, 'final_km' => $complete ? $subtotal / 1000 : null,
            'complete' => $complete, 'spans' => $rows, 'warnings' => array_values(array_unique($warnings)), 'details' => self::DETAILS];
    }

    public function parabolicLength(float $horizontal, float $dz, float $sag): float
    {
        if ($sag === 0.0) {
            return hypot($horizontal, $dz);
        }
        // Adaptive Simpson integration avoids cancellation in the analytic primitive for tiny sag.
        $f = fn (float $t): float => hypot($horizontal, $dz - 4 * $sag + 8 * $sag * $t);
        $integrate = function (float $a, float $b, float $fa, float $fm, float $fb, float $whole, float $eps, int $depth) use (&$integrate, $f): float {
            $m = ($a + $b) / 2;
            $fl = $f(($a + $m) / 2);
            $fr = $f(($m + $b) / 2);
            $left = ($m - $a) * ($fa + 4 * $fl + $fm) / 6;
            $right = ($b - $m) * ($fm + 4 * $fr + $fb) / 6;
            $delta = $left + $right - $whole;
            if ($depth === 0 || abs($delta) <= 15 * $eps) {
                return $left + $right + $delta / 15;
            }

            return $integrate($a, $m, $fa, $fl, $fm, $left, $eps / 2, $depth - 1)
                + $integrate($m, $b, $fm, $fr, $fb, $right, $eps / 2, $depth - 1);
        };

        return max(hypot($horizontal, $dz), $integrate(0, 1, $f(0), $f(0.5), $f(1), ($f(0) + 4 * $f(0.5) + $f(1)) / 6, 1e-9, 18));
    }
}
