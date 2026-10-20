<?php

namespace Tests\Fixtures;

class DataEntrySnapshot
{
    public static function make(): array
    {
        $rows = [];
        foreach (['S', 'E'] as $index => $designation) {
            $rows[] = ['id' => $index + 1, 'pair_number' => 1, 'designation' => $designation, 'manually_verified' => true,
                'group_number' => '01', 'row_date' => '2025-08-08', 'waypoint_reference' => $index ? '002' : '001',
                'composite_identifier' => '0108082500'.($index + 1), 'conductors' => ['R' => 'A', 'Y' => '', 'B' => 'GN', 'N' => 'W'],
                'equipment_type' => $index ? 'SP' : 'TR', 'pole_class' => 'PCO', 'pole_height' => 36, 'pole_height_unit' => 'ft',
                'consumers' => ['rs' => $index ? 3 : 0, 'rl' => 2, 'sc' => null, 'pv' => $index ? 1 : 0], 'pv_details' => $index ? [
                    ['reference' => '001-REF', 'installed_capacity_kw' => 5, 'service_load_kw' => 7, 'meter_number' => '000123', 'remarks' => 'Net meter inside'],
                ] : [], 'intersection' => false];
        }

        return ['transformers' => [['id' => 1, 'code' => 'T-01080825001', 'capacity_kva' => 100, 'header' => ['manually_verified' => true,
            'substation_name' => 'Grid', 'feeder_name' => 'Feeder', 'feeder_identifier' => '10333', 'make' => 'PEL', 'location' => 'Village',
            'mounting' => 'Double Pole', 'service_category' => 'General Duty'], 'entry_rows' => $rows]]];
    }
}
