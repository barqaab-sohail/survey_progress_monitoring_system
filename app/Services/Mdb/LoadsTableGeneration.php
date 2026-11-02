<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Populate Loads from final section relationships; never change the source demand or other tables. */
class LoadsTableGeneration
{
    private const PHASES = ['R', 'Y', 'B'];

    private const QUANTITIES = ['Customers', 'Kw', 'Kvar', 'Kva', 'Kwh'];

    public function apply(array $tables, array $contexts, array $provenance, array $configuration, bool $zeroFillBlankDemand = true): array
    {
        $feeders = array_column($tables['InstFeeders'], null, 'FeederId');
        $sections = $rows = $connections = [];
        $base = $configuration['settings']['mapping']['table_values']['Loads'] ?? [];
        foreach ($tables['InstSection'] as $section) {
            $id = $section['SectionId'];
            $this->check(is_string($id) && ! isset($sections[$id]), 'Loads requires unique text InstSection identifiers.');
            $sections[$id] = $section;
            // The feeder's own section starts at its actual InstFeeders/Node identity.
            if (isset($feeders[$section['FromNodeId']]) && $section['FeederId'] === $section['FromNodeId']) {
                continue;
            }
            $rows[$id] = $this->emptyRow($id, $base);
            $connections[$section['FromNodeId']][$section['ToNodeId']] = $id;
        }

        foreach ($contexts as $context) {
            $transformer = $context['transformer'];
            $entries = $transformer['entry_rows'] ?? [];
            $expanded = collect($entries)->contains(fn ($row) => isset($row['composite_identifier']));
            foreach ($transformer['sections'] as $survey) {
                $label = 'Loads: transformer '.$transformer['code'].', E '.($survey['end_reference'] ?? $survey['id']);
                $mapped = array_values(array_filter($provenance, fn ($p) => $p['survey_section_id'] == $survey['id']));
                $this->check(count($mapped) === 1 && isset($rows[$mapped[0]['section_id']]), $label.': the source section has no unique eligible InstSection relationship.');
                $id = $mapped[0]['section_id'];
                $pole = $sections[$id];
                $plan = $expanded ? (new ConsumerPhaseConnections)->build($survey, $entries, $transformer['pv_records'] ?? [], $label) : [];
                $byCategory = [];
                $pvConnections = [];
                foreach ($plan as $connection) {
                    $endpoint = $pole['ToNodeId'].'.'.$connection['suffix'];
                    $connectionId = $connections[$pole['ToNodeId']][$endpoint] ?? null;
                    $this->check(is_string($connectionId) && isset($rows[$connectionId]), $label.': a generated consumer/PV section endpoint is missing.');
                    if ($connection['kind'] === 'consumer') {
                        $byCategory[$connection['category']][] = $connection + ['section_id' => $connectionId];
                    } elseif ($connection['kind'] === 'pv') {
                        $pvConnections[] = $connection + ['section_id' => $connectionId];
                    }
                }
                $parts = [];
                foreach ($survey['consumers'] ?? [] as $consumer) {
                    if (($consumer['count'] ?? null) === null) {
                        $unknown = $this->emptyRow($id, $base);
                        foreach ($this->available($survey) as $phase) {
                            $index = array_search($phase, self::PHASES, true) + 1;
                            $unknown['Phase'.$index.'Customers'] = null;
                            $unknown['Phase'.$index.'Kva'] = null;
                        }
                        $parts[] = $unknown;

                        continue;
                    }
                    $count = $consumer['count'];
                    $this->check(is_numeric($count) && $count >= 0 && floor((float) $count) === (float) $count, $label.': customer totals must be nonnegative whole numbers.');
                    if ((int) $count === 0) {
                        continue;
                    }
                    $category = $consumer['category'];
                    $assigned = $byCategory[$category] ?? [];
                    $counts = array_fill_keys(self::PHASES, 0);
                    if ($expanded) {
                        $expected = (int) $count * (in_array($category, EntryLookups::SINGLE_PHASE_CONSUMERS, true) ? 1 : 3);
                        $this->check(count($assigned) === $expected, $label.': customer connections do not reconcile to the source count and phase expansion.');
                        foreach ($assigned as $connection) {
                            $counts[$connection['phase']]++;
                        }
                    } else {
                        if ($this->available($survey) === []) {
                            // An incomplete simple model has a known total but no
                            // defensible phase allocation. Keep those fields blank.
                            $counts = array_fill_keys(self::PHASES, null);
                        } else {
                            $weights = [];
                            foreach ($this->available($survey) as $phase) {
                                $weights[$phase] = $consumer['demand']['phase_values'][$phase]['customers'] ?? 0;
                            }
                            if (array_sum($weights) <= 0) {
                                $weights = array_fill_keys($this->available($survey), 1);
                            }
                            $counts = array_replace($counts, $this->apportion((float) $count, $weights, 0));
                            foreach (self::PHASES as $phase) {
                                if (! in_array($phase, $this->available($survey), true)
                                    && ! isset($consumer['demand']['phase_values'][$phase]['customers'])) {
                                    $counts[$phase] = null;
                                }
                            }
                        }
                    }
                    $part = $this->emptyRow($id, $base);
                    foreach (self::PHASES as $i => $phase) {
                        $part['Phase'.($i + 1).'Customers'] = $counts[$phase] === null ? null : (int) $counts[$phase];
                    }
                    $demand = $consumer['demand'] ?? [];
                    foreach (['Kw' => 'kw', 'Kvar' => 'kvar', 'Kva' => 'kva', 'Kwh' => 'kwh'] as $quantity => $sourceField) {
                        $phaseValues = [];
                        foreach (self::PHASES as $phase) {
                            $value = $demand['phase_values'][$phase][$sourceField] ?? null;
                            $this->check($value === null || (is_numeric($value) && is_finite((float) $value)), $label.': invalid source demand value.');
                            $phaseValues[$phase] = $value === null ? null : (float) $value;
                        }
                        // Historic category-rate records may carry only their total
                        // and equal phase fractions. Preserve that total at the
                        // source rate's precision while using the final phase plan.
                        if ($quantity === 'Kva' && ($demand['quantity'] ?? null) === 'kva'
                            && in_array($demand['allocation_basis'] ?? null, ['equal', 'recorded', 'recorded_plus_equal'], true)) {
                            $total = array_sum($phaseValues);
                            $known = array_filter($phaseValues, fn ($v) => $v !== null);
                            $complete = count(array_filter($this->available($survey), fn ($phase) => $phaseValues[$phase] === null)) === 0;
                            if ($known !== [] && $complete) {
                                $sourceRate = round($total / (int) $count, 8);
                                $phaseValues = $this->apportion(round($total, 8), $counts, $this->precision($sourceRate));
                                if (! $expanded) {
                                    foreach (self::PHASES as $phase) {
                                        if (! in_array($phase, $this->available($survey), true)
                                            && ! isset($demand['phase_values'][$phase]['kva'])) {
                                            $phaseValues[$phase] = null;
                                        }
                                    }
                                }
                            }
                        }
                        foreach (self::PHASES as $i => $phase) {
                            $field = 'Phase'.($i + 1).$quantity;
                            $value = $phaseValues[$phase] ?? null;
                            // kWh was historically a mapped setting, rather than a
                            // phase-demand field. Retain an explicit saved value.
                            if ($quantity === 'Kwh' && $value === null && array_key_exists($field, $base)) {
                                $value = $base[$field];
                            }
                            $part[$field] = $value;
                            $phaseConnections = array_values(array_filter($assigned, fn ($c) => $c['phase'] === $phase));
                            if ($phaseConnections === []) {
                                continue;
                            }
                            $split = $value === null ? array_fill(0, count($phaseConnections), null)
                                : array_values($this->apportion((float) $value, array_fill(0, count($phaseConnections), 1), $this->precision((float) $value)));
                            foreach ($phaseConnections as $index => $connection) {
                                $rows[$connection['section_id']][$field] = $split[$index];
                            }
                        }
                    }
                    foreach ($assigned as $connection) {
                        $phaseIndex = array_search($connection['phase'], self::PHASES, true) + 1;
                        $rows[$connection['section_id']]['Phase'.$phaseIndex.'Customers'] = 1;
                    }
                    $parts[] = $part;
                }
                if ($parts !== []) {
                    foreach (self::PHASES as $i => $phase) {
                        foreach (self::QUANTITIES as $quantity) {
                            $field = 'Phase'.($i + 1).$quantity;
                            $values = array_column($parts, $field);
                            $rows[$id][$field] = in_array(null, $values, true) ? null
                                : (count($values) === 1 ? (float) $values[0] : round(array_sum($values), 8));
                        }
                    }
                } elseif (! $expanded) {
                    // Historic mappings represent an omitted inactive phase as
                    // NULL even on a zero-consumer survey span.
                    foreach (array_diff(self::PHASES, $this->available($survey)) as $phase) {
                        $index = array_search($phase, self::PHASES, true) + 1;
                        $rows[$id]['Phase'.$index.'Customers'] = null;
                        $rows[$id]['Phase'.$index.'Kva'] = null;
                    }
                }

                // PV consumers are balanced three-phase entries in Loads. Keep
                // their fractional customer share and 5 kVA per customer on the
                // aggregate span and on the single physical PV connection row.
                foreach ($pvConnections as $connection) {
                    foreach (self::PHASES as $i => $phase) {
                        $customerField = 'Phase'.($i + 1).'Customers';
                        $kvaField = 'Phase'.($i + 1).'Kva';
                        $customerShare = 1 / 3;
                        $kvaShare = EngineeringConfiguration::consumerKva('rl') / 3;
                        $aggregateCustomers = $rows[$id][$customerField];
                        $aggregateKva = $rows[$id][$kvaField];
                        $rows[$id][$customerField] = $aggregateCustomers === null ? null : (float) $aggregateCustomers + $customerShare;
                        $rows[$id][$kvaField] = $aggregateKva === null ? null : (float) $aggregateKva + $kvaShare;
                        $rows[$connection['section_id']][$customerField] = $customerShare;
                        $rows[$connection['section_id']][$kvaField] = $kvaShare;
                    }
                }
            }
        }

        // Retain the order of existing unique load associations, then append
        // newly covered sections in their actual InstSection order.
        $ordered = [];
        foreach ($tables['Loads'] ?? [] as $old) {
            $id = $old['SectionId'];
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
                unset($rows[$id]);
            }
        }

        $result = [...$ordered, ...array_values($rows)];
        // Annual loads use the same allocation before Loads-only display defaults.
        if (! $zeroFillBlankDemand) {
            return $result;
        }
        foreach ($result as &$row) {
            foreach ([
                'Phase1Kw', 'Phase2Kw', 'Phase3Kw',
                'Phase1Kvar', 'Phase2Kvar', 'Phase3Kvar',
                'Phase1Kwh', 'Phase2Kwh', 'Phase3Kwh',
            ] as $field) {
                if ($row[$field] === null || $row[$field] === '') {
                    $row[$field] = 0;
                }
            }
        }
        unset($row);

        return $result;
    }

    private function emptyRow(string $id, array $base): array
    {
        $row = ['SectionId' => $id, 'IsSpotLoad' => array_key_exists('IsSpotLoad', $base) ? $base['IsSpotLoad'] : 0];
        foreach (self::QUANTITIES as $quantity) {
            foreach ([1, 2, 3] as $phase) {
                $field = 'Phase'.$phase.$quantity;
                $row[$field] = in_array($quantity, ['Customers', 'Kva'], true) ? 0.0 : ($base[$field] ?? null);
            }
        }
        $row['LocationToModelSpotLoads'] = array_key_exists('LocationToModelSpotLoads', $base) ? $base['LocationToModelSpotLoads'] : 'C';
        $row['CapacityFactor'] = array_key_exists('CapacityFactor', $base) ? $base['CapacityFactor'] : 1.0;
        $row['Description'] = '';

        return $row;
    }

    private function available(array $survey): array
    {
        $phases = $survey['phases'] ?? [];

        return array_values(array_filter(self::PHASES, fn ($p) => is_array($phases) ? in_array($p, $phases, true) : str_contains($phases, $p)));
    }

    /** Largest-remainder allocation preserves the total without recurring fractions. */
    private function apportion(float $total, array $weights, int $precision): array
    {
        $sum = array_sum($weights);
        $this->check($sum > 0 || abs($total) < 1e-8, 'Loads: a nonzero demand has no available customer phase.');
        $result = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0) {
            return $result;
        }
        $scale = 10 ** $precision;
        $units = (int) round(abs($total) * $scale, 0, PHP_ROUND_HALF_UP);
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $share = $units * $weight / $sum;
            $result[$key] = (int) floor($share);
            $remainders[$key] = $share - $result[$key];
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $units - array_sum($result);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            }
            $result[$key]++;
        }

        return array_map(fn ($v) => ($total < 0 ? -1 : 1) * $v / $scale, $result);
    }

    private function precision(float $value): int
    {
        $text = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return str_contains($text, '.') ? strlen(explode('.', $text)[1]) : 0;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
