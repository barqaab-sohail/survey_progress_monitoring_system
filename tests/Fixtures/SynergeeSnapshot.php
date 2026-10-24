<?php

namespace Tests\Fixtures;

use App\Services\Mdb\ApprovedSchema;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\SnapshotService;

/** Synthetic engineering approvals and values for tests only; never production defaults. */
class SynergeeSnapshot
{
    public static function gpx(): string
    {
        return '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31.7" lon="73.5"><ele>0</ele><name>001</name></wpt><wpt lat="31.7" lon="73.501"><ele>10</ele><name>002</name></wpt></gpx>';
    }

    public static function make(): array
    {
        $stamp = '2026-10-09T00:00:00Z';
        $fields = ['SectionPhases' => 'R  N', 'PhaseConductorId' => 'DOG', 'PhaseConductor2Id' => null,
            'PhaseConductor3Id' => null, 'NeutralConductorId' => 'DOG', 'ConfigurationId' => 'POLE', 'SectionLength_MUL' => 1];
        $mapping = ['version' => ExportPayloadBuilder::VERSION, 'reviewed_by' => 1, 'reviewed_at' => $stamp,
            'table_values' => ['SAI_Control' => ['Frequency' => 50, 'LengthUnits' => 'Metric', 'Product' => 'SynerGEE Electric 5.0.0.324', 'ProjectionFile' => 'WGS 84 UTM 43N'],
                'Node' => [], 'InstSection' => ['ConfigurationId' => 'POLE'], 'InstFeeders' => ['SubstationId' => 'Synthetic grid', 'NominalKvll' => 11],
                'InstPrimaryTransformers' => [], 'Loads' => []],
            'networks' => ['T-SYNTHETIC' => ['source_nodes' => [['key' => 'source', 'waypoint_id' => 1], ['key' => 'hv', 'waypoint_id' => 1]],
                'source_sections' => [['key' => 'supply', 'from' => 'source', 'to' => 'hv', 'fields' => $fields], ['key' => 'transformer', 'from' => 'hv', 'to' => 'wp:1', 'fields' => $fields]],
                'feeder_node' => 'source', 'transformer_section' => 'transformer',
                'transformer_fields' => ['TransformerType' => '100KVA', 'ConnectedPhases' => 'R', 'SpecNomKv' => 0.4, 'UseInstanceImpedance' => 0]]]];
        $consumers = array_map(fn ($category) => ['category' => $category, 'count' => 0, 'original_value' => '0', 'demand' => null], ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st']);
        $consumers[0] = ['category' => 'rs', 'count' => 3, 'demand' => ['method' => 'Synthetic measured fixture', 'evidence' => 'Test evidence', 'approved_by' => 1,
            'phase_values' => ['R' => ['customers' => 3, 'kw' => 2.4, 'kvar' => 1.8, 'kva' => 3]]]];

        return [
            'schema_version' => 1, 'batch' => ['id' => 1, 'project_id' => 1, 'feeder_id' => 1, 'revision' => 1],
            'configuration' => ['epsg' => 32643, 'approved_by' => 1, 'approved_at' => $stamp, 'load_assumptions' => ['method' => 'Synthetic measured fixture'],
                'settings' => ['frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'conductor_library_revision' => 'fixture', 'transformer_library_revision' => 'fixture', 'mapping' => $mapping]],
            'template' => ['id' => 1, 'code' => 'synthetic', 'version' => '1', 'sha256' => hash_file('sha256', resource_path('mdb/synergee-empty.mdb')),
                'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'approved_by' => 1, 'approved_at' => $stamp,
                'metadata' => ['equipment_references' => ['conductors' => ['DOG'], 'configurations' => ['POLE'], 'transformers' => ['100KVA']]]],
            'sources' => [['id' => 1, 'kind' => 'gpx', 'status' => 'ready', 'original_name' => 'synthetic.gpx', 'sha256' => hash('sha256', self::gpx())],
                ['id' => 2, 'kind' => 'pdf', 'status' => 'ready', 'original_name' => 'synthetic.pdf', 'sha256' => hash('sha256', '%PDF fixture'), 'metadata' => ['page_count' => 1]]],
            'waypoints' => [['id' => 1, 'batch_id' => 1, 'source_file_id' => 1, 'name' => '001', 'latitude' => 31.7, 'longitude' => 73.5, 'elevation' => 0],
                ['id' => 2, 'batch_id' => 1, 'source_file_id' => 1, 'name' => '002', 'latitude' => 31.7, 'longitude' => 73.501, 'elevation' => 10]],
            'transformers' => [['id' => 1, 'code' => 'T-SYNTHETIC', 'capacity_kva' => 100, 'source_waypoint_id' => 1, 'header' => ['manually_verified' => true,
                'feeder_identifier' => 'F1', 'feeder_name' => 'Synthetic feeder', 'substation_identifier' => 'G1', 'substation_name' => 'Synthetic grid', 'make' => 'Test make',
                'location' => 'Test location', 'mounting' => 'Double pole', 'survey_date' => '2026-10-09', 'team_group' => '01', 'inspector' => 'Fixture',
                'pages' => [['source_pdf_id' => 2, 'page' => 1, 'role' => 'header', 'confirmed' => true]]],
                'entry_rows' => [], 'pv_records' => [], 'sections' => [['id' => 1, 'start_waypoint_id' => 1, 'end_waypoint_id' => 2, 'start_reference' => '001', 'end_reference' => '002',
                    'start_source_file_id' => 1, 'end_source_file_id' => 1, 'phases' => ['R', 'N'], 'conductors' => ['R' => 'DOG', 'N' => 'DOG'],
                    'equipment_type' => 'pole', 'equipment_ref' => 'POLE', 'pole_class' => 'concrete', 'pole_height' => 11, 'pole_height_unit' => 'm',
                    'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => '1', 'original_entry' => ['manually_verified' => true], 'consumers' => $consumers]]]],
        ];
    }

    public static function package(?array $snapshot = null): array
    {
        $snapshot ??= self::make();
        $hashes = new SnapshotService;
        $content = ['format' => 'hazeco-synergee-transfer', 'version' => 1, 'schema_sha256' => ApprovedSchema::fingerprint(),
            'source' => ['batch_id' => 1, 'revision_id' => 1, 'revision_number' => 1, 'revision_sha256' => $hashes->hash($snapshot), 'exported_at' => '2026-10-24T00:00:00Z'],
            'scope' => ['transformer_id' => 1, 'transformer_codes' => ['T-SYNTHETIC']], 'snapshot' => $snapshot,
            'template_base64' => base64_encode(file_get_contents(resource_path('mdb/synergee-empty.mdb')))];

        return $content + ['sha256' => $hashes->hash($content)];
    }
}
