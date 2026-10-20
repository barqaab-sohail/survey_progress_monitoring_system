<?php

namespace App\Services\Mdb;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** The original PDF observations, never the derived SynerGEE sections. */
class DataEntryPayload
{
    public function build(array $snapshot): array
    {
        $schema = json_decode(file_get_contents(resource_path('mdb/data-entry/schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $fields = $schema['tables'][0]['fields'];
        $rows = $warnings = $seen = [];
        $warn = static function (string $message) use (&$warnings): void {
            $warnings[$message] = $message;
        };
        $warn('PDF provenance, verification evidence, copy history and internal pair IDs have no columns in this exact legacy schema. They remain in the approved application snapshot.');
        $warn('RS maps to RS_Con1 and RL to RS_Con3. Legacy RS_Con has no confirmed equivalent and is left NULL.');
        $warn('Numeric GPS fields cannot retain leading zeros; the complete textual waypoint is retained in Pole_Number. Inspector, client header notes and separate division/subdivision names have no equivalent legacy columns.');
        $transformers = $snapshot['transformers'] ?? [];
        usort($transformers, fn ($a, $b) => $a['id'] <=> $b['id']);
        foreach ($transformers as $transformer) {
            $header = $transformer['header'] ?? [];
            $entries = $transformer['entry_rows'] ?? [];
            $this->require($entries !== [], 'A transformer has no original PDF entry rows; derived sections cannot be exported as observations.');
            $this->require(! empty($header['manually_verified']), 'Transformer headers must be verified.');
            foreach ($transformer['pv_records'] ?? [] as $pv) {
                if (empty($pv['entry_row_id']) && empty($pv['original_entry']['entry_row_id'])) {
                    $warn($transformer['code'].': a transformer-level PV record has no original S/E observation link; it cannot be placed in a legacy row and is omitted.');
                }
            }
            foreach ($transformer['sections'] ?? [] as $section) {
                $this->require(! empty($section['original_entry']['entry_rows_managed']), 'This snapshot includes a section without original PDF S/E rows; it cannot be exported without omitting observations.');
            }
            usort($entries, fn ($a, $b) => [$a['pair_number'], $a['designation'] === 'S' ? 0 : 1] <=> [$b['pair_number'], $b['designation'] === 'S' ? 0 : 1]);
            $pairs = [];
            foreach ($entries as $entry) {
                $this->require(! empty($entry['manually_verified']), 'All PDF observations must be verified.');
                $this->require(isset($entry['id']) && ! isset($seen[$entry['id']]), 'Duplicate or missing PDF observation identity.');
                $seen[$entry['id']] = true;
                $pair = $entry['pair_number'];
                $designation = $entry['designation'];
                $this->require(in_array($designation, ['S', 'E'], true) && ! isset($pairs[$pair][$designation]), 'Duplicate or invalid S/E pair slot.');
                $pairs[$pair][$designation] = true;
            }
            foreach ($pairs as $pair) {
                $this->require(count($pair) === 2, 'Every section must have exactly one S and one E observation.');
            }

            foreach ($entries as $index => $entry) {
                $label = $transformer['code'].' pair '.$entry['pair_number'].' '.$entry['designation'];
                $row = [];
                // Unknown values are explicitly NULL; schema defaults such as 11kV must not invent observations.
                foreach ($fields as $field) {
                    $row[$field['name']] = null;
                }
                $row['ID1'] = count($rows) + 1;
                $row['Sequence'] = 0;
                $row['Substation'] = $header['substation_name'] ?? null;
                $row['Feeder'] = $header['feeder_name'] ?? null;
                $group = $entry['group_number'] ?? null;
                $row['Group'] = is_string($group) && preg_match('/^\d+$/D', $group)
                    ? (ltrim($group, '0') ?: '0')
                    : $group;
                $row['DISCO'] = 'HAZECO';
                $row['Line_Voltage'] = '0.4kV';
                $row['Pole_Use'] = 'Secondary Distribution';
                $row['Survey_Date'] = ! empty($entry['row_date']) ? CarbonImmutable::parse($entry['row_date'])->format('d/m/Y') : null;
                $row['S_E'] = $entry['designation'];
                $row['GPS_WP'] = $this->numericIdentifier($entry['waypoint_reference'] ?? null, $warn, $label.' GPS_WP');
                $identity = $entry['composite_identifier'] ?? null;
                if (! $identity && preg_match('/^\d{1,2}$/D', (string) ($entry['group_number'] ?? '')) && ! empty($entry['row_date']) && preg_match('/^\d{1,3}$/D', (string) ($entry['waypoint_reference'] ?? ''))) {
                    $identity = str_pad($entry['group_number'], 2, '0', STR_PAD_LEFT).CarbonImmutable::parse($entry['row_date'])->format('dmy').str_pad($entry['waypoint_reference'], 3, '0', STR_PAD_LEFT);
                }
                $row['GPS_NO'] = $this->numericIdentifier($identity, $warn, $label.' GPS_NO');
                $row['Pole_Number'] = $identity;
                $conductors = $entry['conductors'] ?? [];
                $phases = [];
                foreach (['R' => 'A', 'Y' => 'B', 'B' => 'C', 'N' => 'N'] as $phase => $column) {
                    $value = $conductors[$phase] ?? null;
                    $row['Con_Size_'.$column] = match ($value) {
                        'A', 'ANT' => 'Ant', 'W', 'WASP' => 'Wasp', 'GN' => 'GNAT', default => $value
                    };
                    if ($phase !== 'N' && $value !== null && $value !== '') {
                        $phases[] = $phase;
                    }
                }
                $row['Pole_Phase'] = $phases ? implode('-', $phases) : null;
                $height = $entry['pole_height'] ?? null;
                if ($height !== null && ($entry['pole_height_unit'] ?? null) === 'm') {
                    $height = round($height * 3.280839895, 4);
                    $warn($label.': metric pole height converted to feet to four decimal places.');
                }
                if ($height !== null && ! in_array($entry['pole_height_unit'] ?? null, ['m', 'ft'], true)) {
                    $warn($label.': pole height omitted because its unit is unknown.');
                    $height = null;
                }
                $row['Pole_height'] = $height === null ? null : (string) $height;
                $row['Pole_Type'] = match ($entry['pole_class'] ?? null) {
                    'S' => 'Steel Structure', 'PCO' => 'PC Ordinary', 'PCS' => 'PC Spun', 'RS' => 'Rail Steel', 'TS' => 'Tubular Steel', 'WB' => 'Wall Bracket', default => $entry['pole_class'] ?? null
                };
                $row['Equip_Type'] = match ($entry['equipment_type'] ?? null) {
                    'TR' => 'Transformer', 'SP' => 'Secondary Pole', 'Ang' => 'Angle', 'WB' => 'Wall Bracket', default => $entry['equipment_type'] ?? null
                };
                $row['Equip_Number'] = $transformer['code'];
                // Reference convention: transformer attributes belong on the first S observation only.
                if ($index === 0) {
                    $row['Equip_Size'] = isset($transformer['capacity_kva']) ? (string) $transformer['capacity_kva'] : null;
                    foreach (['make' => 'Transformer_Make', 'location' => 'Transformer_Location', 'mounting' => 'Transformer_Mounting', 'service_category' => 'Equip_Use'] as $key => $column) {
                        $row[$column] = $header[$key] ?? null;
                    }
                } else {
                    $row['Equip_Size'] = '0';
                }
                foreach (['rs' => 'RS_Con1', 'rl' => 'RS_Con3', 'sc' => 'SC_Con', 'lc' => 'LC_Con', 'si' => 'SI_Con', 'li' => 'LI_Con', 'pb' => 'PB_Con', 'ag' => 'AG_Con', 'st' => 'ST_Con', 'pv' => 'PV_Con'] as $key => $column) {
                    $count = $entry['consumers'][$key] ?? null;
                    $row[$column] = $count === null || $count === '' ? 0 : $count;
                }
                $row['Feeder_Code'] = $this->integer($header['feeder_identifier'] ?? null, $warn, $label.' Feeder_Code');
                $details = $entry['pv_details'] ?? [];
                // Never duplicate a pole row or arbitrarily choose a consumer to fit this scalar schema.
                if (count($details) > 1) {
                    $warn($label.': multiple PV details cannot fit the single legacy PV slot; individual PV details omitted, PV count retained.');
                } elseif (count($details) === 1) {
                    $pv = $details[0];
                    $row['PV_Consumer_REF_NO'] = $pv['reference'] ?? null;
                    $row['PV_Size'] = $this->integer($pv['installed_capacity_kw'] ?? null, $warn, $label.' PV_Size');
                    $row['AMI_Meter_No'] = $pv['meter_number'] ?? null;
                    $row['Remarks'] = $pv['remarks'] ?? null;
                    if (($pv['service_load_kw'] ?? null) !== null) {
                        $warn($label.': service load is not mapped to Sanction Load because their equivalence is unconfirmed.');
                    }
                }
                if (! empty($entry['intersection'])) {
                    $warn($label.': intersection flag has no confirmed equivalent in Angle, Tap_Pole or End_Type.');
                }
                foreach ($fields as $field) {
                    if ($field['type'] === 10 && $row[$field['name']] !== null && strlen(mb_convert_encoding((string) $row[$field['name']], 'UTF-16LE', 'UTF-8')) / 2 > $field['size']) {
                        $warn($label.': '.$field['name'].' exceeds '.$field['size'].' characters; omitted without truncation.');
                        $row[$field['name']] = null;
                    }
                }
                $rows[] = $row;
            }
        }
        $this->require($rows !== [], 'There are no verified PDF entry observations to export.');
        $warn('DISCO is set to HAZECO, line voltage to 0.4kV and pole use to Secondary Distribution for every exported observation. Blank mapped consumer counts are exported as 0. Feeders-on-pole, equipment status/phase/unit, photo paths and administrative codes remain NULL. GPS coordinates remain NULL and GPS_Points is empty.');
        $warn('Pole heights are exported in feet. TR/SP/Ang/WB and pole-class codes use the printed-form label mapping; LC retains its existing category without reclassification.');

        return ['version' => 1, 'rows' => $rows, 'warnings' => array_values($warnings), 'transformer_count' => count($transformers)];
    }

    private function numericIdentifier(mixed $value, callable $warn, string $label): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! preg_match('/^\d{1,15}$/D', (string) $value)) {
            $warn($label.': identifier cannot be represented safely as Double; numeric field omitted.');

            return null;
        }

        return (float) $value;
    }

    private function integer(mixed $value, callable $warn, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value != floor((float) $value) || (float) $value < -2147483648 || (float) $value > 2147483647) {
            $warn($label.': value cannot fit legacy Long Integer; omitted without rounding.');

            return null;
        }

        return (int) $value;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['data_entry_mdb' => $message]);
        }
    }
}
