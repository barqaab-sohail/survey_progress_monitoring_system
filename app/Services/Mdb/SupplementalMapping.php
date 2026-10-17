<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Explicit v2 device mappings. Reference MDB observations never become defaults. */
class SupplementalMapping
{
    public const TABLES = ['InstMeterPoints', 'InstDGens', 'InstMymDGens'];

    public function build(array $transformer, array $configuration): array
    {
        $simple = GenerationMode::simple($configuration);
        $mapping = $configuration['settings']['mapping'] ?? [];
        $network = $mapping['networks'][$transformer['code']] ?? [];
        $this->check(is_array($network), 'Device mapping must be an object.');
        $tables = array_fill_keys(self::TABLES, []);
        $sections = array_column($transformer['sections'] ?? [], null, 'id');
        $sources = array_column($network['source_sections'] ?? [], null, 'key');
        $base = $mapping['table_values'] ?? [];
        foreach (self::TABLES as $table) {
            if ($simple && ! isset($base[$table])) {
                $base[$table] = [];
            }
            $this->check(is_array($base[$table] ?? null), 'Explicit reviewed table values are required for '.$table.'.');
            $this->check(! array_key_exists('SectionId', $base[$table]), 'Device section IDs must be resolved from the current survey.');
        }
        $meters = $network['meter_points'] ?? [];
        $generators = $network['generators'] ?? [];
        $this->check(is_array($meters) && array_is_list($meters) && is_array($generators) && array_is_list($generators), 'Meter points and generators must be lists.');
        $usedMeters = [];
        foreach ($meters as $meter) {
            $this->check(is_array($meter), 'Each meter point must be an object.');
            $isSource = isset($meter['source_section']);
            $this->check($isSource !== isset($meter['survey_section_id']), 'Meter point needs exactly one source_section or survey_section_id.');
            $key = $isSource ? $meter['source_section'] : $meter['survey_section_id'];
            $this->check(is_string($key) || is_int($key), 'Meter section reference must be a scalar ID.');
            $this->check(isset(($isSource ? $sources : $sections)[$key]), 'Meter point refers to a section outside this transformer.');
            $reference = ($isSource ? 'source:' : 'survey:').$key;
            $this->check(! isset($usedMeters[$reference]), 'Only one meter point is supported per section.');
            $usedMeters[$reference] = true;
            $tables['InstMeterPoints'][] = $this->row($base['InstMeterPoints'], $meter['fields'] ?? [], $reference);
        }
        $pvRecords = array_column($transformer['pv_records'] ?? [], null, 'id');
        $usedPv = $usedSections = [];
        foreach ($generators as $generator) {
            $this->check(is_array($generator), 'Each generator must be an object.');
            $pvId = $generator['pv_record_id'] ?? null;
            $this->check(is_int($pvId) || is_string($pvId), 'Generator needs a current PV record ID.');
            $pv = $pvRecords[$pvId] ?? null;
            $this->check($pv !== null && ($simple || (float) ($pv['installed_capacity_kw'] ?? 0) > 0), 'Generator needs a positive-capacity PV record from this transformer.');
            $sectionId = $pv['section_id'] ?? null;
            $this->check(is_string($sectionId) || is_int($sectionId), 'Generator PV record needs a section ID.');
            $this->check(isset($sections[$sectionId]), 'Generator PV record must be linked to a section of this transformer.');
            $this->check(! isset($usedPv[$pvId]) && ! isset($usedSections[$sectionId]), 'Only one generator mapping is supported per PV record and section.');
            $usedPv[$pvId] = $usedSections[$sectionId] = true;
            $reference = 'survey:'.$sectionId;
            $row = $this->row($base['InstDGens'], $generator['fields'] ?? [], $reference);
            foreach (['DGenOutputPct', 'DGenVoltSet'] as $field) {
                $this->check(($simple && ! isset($row[$field])) || ($this->finite($row[$field] ?? null) && (float) $row[$field] >= 0), 'Generator needs explicit nonnegative '.$field.'.');
            }
            $this->check((! isset($row['DGenOutputPct']) && $simple || (float) ($row['DGenOutputPct'] ?? 0) <= 100) && (! isset($row['DGenVoltSet']) && $simple || (float) ($row['DGenVoltSet'] ?? 0) > 0), 'Generator output percentage or voltage setting is invalid.');
            $tables['InstDGens'][] = $row;
            $years = $generator['years'] ?? [];
            $this->check(is_array($years) && array_is_list($years) && ($simple || $years !== []), 'Generator requires explicit yearly phase outputs including base year 0.');
            $usedYears = [];
            $phases = $sections[$sectionId]['phases'] ?? [];
            foreach ($years as $year) {
                $row = $this->row($base['InstMymDGens'], $year, $reference);
                $number = $row['YearNumber'] ?? null;
                $this->check(is_int($number) && $number >= 0 && ! isset($usedYears[$number]), 'Generator year numbers must be unique nonnegative integers.');
                $usedYears[$number] = true;
                $this->check(($simple && ! isset($row['CapacityFactor'])) || ($this->finite($row['CapacityFactor'] ?? null) && $row['CapacityFactor'] >= 0 && $row['CapacityFactor'] <= 1), 'Generator yearly capacity factor must be explicit and between 0 and 1.');
                foreach (['R', 'Y', 'B'] as $index => $phase) {
                    $active = is_array($phases) ? in_array($phase, $phases, true) : str_contains($phases, $phase);
                    foreach (['Kw', 'Kvar'] as $quantity) {
                        $field = 'Phase'.($index + 1).$quantity;
                        $value = $row[$field] ?? null;
                        $this->check((($simple || ! $active) && $value === null) || ($this->finite($value) && ($quantity === 'Kvar' || $value >= 0)), 'Generator needs finite phase outputs; kW must be nonnegative.');
                        $this->check(($simple && empty($phases)) || $active || $value === null || (float) $value === 0.0, 'Generator output cannot use a disconnected phase.');
                        $row[$field] = $value === null ? null : (float) $value;
                    }
                }
                $tables['InstMymDGens'][] = $row;
            }
            $this->check($simple || isset($usedYears[0]), 'Generator requires a base-year (0) output record.');
        }
        $exclusion = $mapping['pv_exclusion'] ?? [];
        foreach ($transformer['pv_records'] ?? [] as $pv) {
            if (! $simple && (float) ($pv['installed_capacity_kw'] ?? 0) > 0 && ! isset($usedPv[$pv['id'] ?? ''])) {
                $this->check(! empty($exclusion['reason']) && ! empty($exclusion['reviewed_at']) && ! empty($exclusion['reviewed_by']) && (int) $exclusion['reviewed_by'] === (int) ($configuration['approved_by'] ?? 0), 'Every positive PV record needs a reviewed generator mapping or engineering-approved exclusion.');
            }
        }

        return $tables;
    }

    private function row(array $base, mixed $fields, string $reference): array
    {
        $this->check(is_array($fields) && ! array_key_exists('SectionId', $fields), 'Device fields must be an object without a copied SectionId.');

        return array_replace($base, $fields, ['SectionId' => $reference]);
    }

    private function finite(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
