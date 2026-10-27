<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mdb\Configuration;
use App\Models\Project;
use App\Models\User;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\ReferenceEngineeringImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReferenceEngineeringImportTest extends TestCase
{
    use RefreshDatabase;

    private function documents(): array
    {
        $documents = [];
        for ($index = 1; $index <= 10; $index++) {
            $supply = ['SectionId' => 'supply-'.$index, 'FromNodeId' => 'source-'.$index, 'ToNodeId' => 'hv-'.$index,
                'SectionPhases' => 'RYB', 'PhaseConductorId' => 'DOG', 'PhaseConductor2Id' => ' DOG', 'PhaseConductor3Id' => 'DOG', 'NeutralConductorId' => '',
                'ConfigurationId' => 'approved-line', 'PhaseToPhaseSpacing_MUL' => 121.9, 'PhaseToNeutralSpacing_MUL' => 91.4, 'AveHeightAboveGround_MUL' => 9.1,
                'SectionLength_MUL' => 1, 'LoadConnectionPhaseGnd' => 0];
            $secondary = array_replace($supply, ['SectionId' => 'secondary-'.$index, 'FromNodeId' => 'hv-'.$index, 'ToNodeId' => 'lv-'.$index,
                'SectionPhases' => 'RYBN', 'PhaseConductorId' => 'ANT', 'PhaseConductor2Id' => ' ANT', 'PhaseConductor3Id' => 'ANT', 'LoadConnectionPhaseGnd' => 1]);
            $rows = [
                'SAI_Control' => [['Frequency' => 60, 'LengthUnits' => 'Metric', 'ProjectionFile' => 'WGS 1984 UTM Zone 43N', 'ProjectionWKT' => '', 'Product' => 'fixture']],
                'Node' => [['NodeId' => 'node-'.$index, 'X' => $index, 'Y' => $index, 'IsPadMountGear' => 0]],
                'InstFeeders' => [['FeederId' => 'old-feeder-'.$index, 'SubstationId' => 'old-grid', 'NominalKvll' => 11, 'BusVoltageLevel' => 11.37]],
                'InstPrimaryTransformers' => [['SectionId' => 'supply-'.$index, 'UniqueDeviceId' => 'T-'.$index, 'TransformerType' => '200 KVA', 'UseInstanceImpedance' => 0, 'SpecNomKv' => 4.16, 'NomKvMode' => 'C']],
                'InstSection' => [$supply, $secondary],
                'Loads' => [['SectionId' => 'secondary-'.$index, 'IsSpotLoad' => 0, 'CapacityFactor' => 1, 'LocationToModelSpotLoads' => 'C',
                    'Phase1Customers' => 3, 'Phase1Kw' => 0, 'Phase1Kvar' => 0, 'Phase1Kva' => $index === 10 ? 12 : 9]],
                'InstMeterPoints' => [],
                'InstDGens' => $index <= 6 ? [['SectionId' => 'secondary-'.$index, 'DGenOutputPct' => 100, 'DGenVoltSet' => 120]] : [],
                'InstMymDGens' => $index <= 6 ? [['SectionId' => 'secondary-'.$index, 'YearNumber' => 0, 'CapacityFactor' => 1, 'Phase1Kw' => $index]] : [],
            ];
            $tables = [];
            foreach ($rows as $table => $records) {
                $tables[] = ['name' => $table, 'fields' => [], 'indexes' => [], 'count' => count($records), 'rows' => $records];
            }
            $documents[] = ['file' => 'reference-'.$index.'.mdb', 'sha256' => hash('sha256', 'fixture-'.$index), 'tables' => $tables, 'relationships' => []];
        }

        return $documents;
    }

    public function test_extracts_consistent_values_without_inventing_missing_engineering_choices(): void
    {
        $result = app(ReferenceEngineeringImport::class)->analyze($this->documents());
        $this->assertSame(32643, $result['epsg']);
        $this->assertSame(60, $result['settings']['frequency_hz']);
        $this->assertSame(11, $result['settings']['nominal_voltage_kv']);
        $this->assertNull($result['settings']['secondary_voltage_kv']);
        $this->assertTrue($result['settings']['engineering']['blank_consumers_zero']);
        $this->assertSame('ft', $result['settings']['entry']['pole_height_unit']);
        $this->assertSame(2000, $result['settings']['entry']['two_digit_year_start']);
        $this->assertNull($result['settings']['engineering']['supply_conductor_id']);
        $this->assertNull($result['load_assumptions']['mode']);
        $this->assertSame(EngineeringConfiguration::consumerRates(), $result['load_assumptions']['rates']);
        $this->assertSame([], $result['settings']['mapping']['networks']);
        $values = $result['settings']['mapping']['table_values'];
        $this->assertSame(4.16, $values['InstPrimaryTransformers']['SpecNomKv']);
        $this->assertArrayNotHasKey('UniqueDeviceId', $values['InstPrimaryTransformers']);
        $this->assertArrayNotHasKey('SubstationId', $values['InstFeeders']);
        $this->assertArrayNotHasKey('X', $values['Node']);
        $this->assertArrayNotHasKey('LoadConnectionPhaseGnd', $values['InstSection']);
        $this->assertSame(100, $values['InstDGens']['DGenOutputPct']);
        $this->assertSame(3, $result['settings']['reference_import']['conditional_parameter_count']);
    }

    public function test_reports_literal_library_variants_and_inconsistent_demand_and_neutral_evidence(): void
    {
        $result = app(ReferenceEngineeringImport::class)->analyze($this->documents());
        $evidence = $result['settings']['reference_import'];
        $this->assertContains('DOG', $evidence['equipment_references']['conductors']);
        $this->assertContains(' DOG', $evidence['equipment_references']['conductors']);
        $this->assertSame(9, $evidence['load_evidence']['three_kva_per_customer_records']);
        $this->assertSame(1, $evidence['load_evidence']['other_ratio_records']);
        $fields = array_column($evidence['conflicts'], 'field');
        $this->assertContains('Loads.observed_kva_per_customer', $fields);
        $this->assertContains('Loads.power_factor_inputs', $fields);
        $this->assertContains('transformer_secondary_link.neutral', $fields);
        $this->assertContains('equipment_references.conductors.DOG', $fields);
        $this->assertSame(' DOG', $evidence['role_table_values']['transformer_supply']['PhaseConductor2Id']);
    }

    public function test_import_populates_an_editable_unapproved_configuration_without_creating_templates_or_networks(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'HAZECO', 'name' => 'HAZECO', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $importer = app(ReferenceEngineeringImport::class);
        $configuration = $importer->import($project, $admin, $importer->analyze($this->documents()));
        $this->assertNull($configuration->approved_by);
        $this->assertNull($configuration->approved_at);
        $this->assertSame(1, $configuration->revision);
        $this->assertDatabaseCount('mdb_workflow_templates', 0);
        $this->assertDatabaseCount('mdb_workflow_batches', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'mdb.engineering_reference_imported']);
        $this->assertCount(10, $configuration->settings['reference_import']['sources']);
        $this->expectException(ValidationException::class);
        $importer->import($project, $admin, $importer->analyze($this->documents()));
    }

    public function test_rejects_schema_mismatch_before_any_write(): void
    {
        $documents = $this->documents();
        $documents[9]['tables'][0]['fields'][] = ['name' => 'unexpected'];
        $this->expectException(ValidationException::class);
        app(ReferenceEngineeringImport::class)->analyze($documents);
    }

    public function test_rejects_incomplete_reference_set(): void
    {
        $this->expectException(ValidationException::class);
        app(ReferenceEngineeringImport::class)->analyze(array_slice($this->documents(), 0, 9));
    }

    public function test_additional_batches_and_combined_evidence_keep_exact_source_count_and_references(): void
    {
        $documents = $this->documents();
        $additional = array_slice($documents, 0, 5);
        foreach ($additional as $index => &$document) {
            $document['file'] = 'additional-'.$index.'.mdb';
            $document['sha256'] = hash('sha256', $document['file']);
            foreach ($document['tables'] as &$table) {
                if ($table['name'] === 'InstPrimaryTransformers') {
                    $table['rows'][0]['TransformerType'] = '50 KVA';
                }
            }
            unset($table);
        }
        unset($document);
        $importer = app(ReferenceEngineeringImport::class);
        $batch = $importer->analyze($additional, 5);
        $combined = $importer->analyze([...$documents, ...$additional], 15);
        $this->assertCount(5, $batch['settings']['reference_import']['sources']);
        $this->assertCount(15, $combined['settings']['reference_import']['sources']);
        $this->assertSame(15, $combined['field_evidence']['SAI_Control.Frequency']['files_with_rows']);
        $this->assertContains('50 KVA', $combined['settings']['reference_import']['equipment_references']['transformers']);
        $this->assertContains('200 KVA', $combined['settings']['reference_import']['equipment_references']['transformers']);
        $this->assertSame([], $combined['settings']['engineering']['transformer_types']);
        $this->assertStringStartsWith('15 reference MDBs', $combined['load_assumptions']['evidence']);
    }

    public function test_equivalent_demand_ratios_do_not_create_a_floating_point_conflict(): void
    {
        $documents = array_slice($this->documents(), 0, 5);
        foreach ($documents[0]['tables'] as &$table) {
            if ($table['name'] === 'Loads') {
                $table['rows'][0]['Phase1Kva'] = 9.000000000000002;
            }
        }
        unset($table);
        $analysis = app(ReferenceEngineeringImport::class)->analyze($documents, 5);
        $this->assertSame(5, $analysis['settings']['reference_import']['load_evidence']['three_kva_per_customer_records']);
        $this->assertNotContains('Loads.observed_kva_per_customer', array_column($analysis['settings']['reference_import']['conflicts'], 'field'));
    }

    public function test_non_admin_cannot_apply_reference_import(): void
    {
        $user = User::factory()->create(['role' => UserRole::ProjectManager]);
        $project = Project::create(['code' => 'HAZECO', 'name' => 'HAZECO', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        try {
            app(ReferenceEngineeringImport::class)->import($project, $user, []);
            $this->fail('Non-Admin import must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, Configuration::count());
    }
}
