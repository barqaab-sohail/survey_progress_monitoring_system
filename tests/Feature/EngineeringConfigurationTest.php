<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\ExportJob;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\TransformerMdbProject;
use App\Models\User;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\SnapshotService;
use App\Services\TransformerMdbNetworkService;
use App\Services\TransformerMdbWriter;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngineeringConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private Template $template;

    private Feeder $feeder;

    private SurveyBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'HAZECO', 'name' => 'HAZECO', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $subdivision = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'G']);
        $this->feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F', 'feeder_name' => 'Feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'T', 'name' => 'T', 'status' => 'active']);
        $this->batch = SurveyBatch::create(['project_id' => $this->project->id, 'feeder_id' => $this->feeder->id, 'survey_team_id' => $team->id, 'survey_date' => '2026-10-01', 'created_by' => $this->admin->id]);
        $this->template = Template::create(['code' => 'fixture', 'version' => '1', 'disk' => 'local', 'path' => 'template.mdb', 'sha256' => str_repeat('a', 64),
            'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'active' => true, 'approved_by' => $this->admin->id, 'approved_at' => now('UTC'),
            'metadata' => ['equipment_references' => ['conductors' => ['ANT'], 'transformers' => ['approved-100'], 'configurations' => ['approved-line']]]]);
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->with([], 32642)->andReturn([]);
        $this->actingAs($this->admin);
    }

    private function payload(): array
    {
        return ['revision' => 0, 'epsg' => 32642, 'crs_name' => 'WGS 1984 UTM Zone 42N', 'projection_wkt' => '',
            'frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'secondary_voltage_kv' => 0.4, 'template_id' => $this->template->id,
            'conductor_library_revision' => 'fixture-conductors-v1', 'transformer_library_revision' => 'fixture-transformers-v1',
            'configuration_id' => 'approved-line', 'supply_conductor_id' => 'ANT', 'secondary_conductor_id' => 'ANT', 'phase_spacing_cm' => 100, 'neutral_spacing_cm' => 75, 'conductor_height_m' => 8,
            'pole_height_unit' => 'ft', 'two_digit_year_start' => 2000, 'load_mode' => 'category_rates', 'load_evidence' => 'Synthetic approved test method',
            'blank_consumers_zero' => 0, 'rates' => array_fill_keys(EngineeringConfiguration::CATEGORIES, ['kva' => 3, 'power_factor' => 0.8]),
            'mapping_json' => json_encode(['version' => 'synergee-reviewed-v1', 'table_values' => [
                'SAI_Control' => ['Frequency' => 60], 'Node' => (object) [], 'InstFeeders' => (object) [], 'InstSection' => (object) [],
                'InstPrimaryTransformers' => ['UseInstanceImpedance' => 0], 'Loads' => (object) [],
            ], 'networks' => (object) []]),
            'transformer_types_json' => '{"100":"approved-100"}', 'reason' => 'Approved fixture engineering standard', 'approved' => 1];
    }

    private function saveConfiguration(): Configuration
    {
        $this->put(route('engineering.update', $this->project), $this->payload())->assertRedirect()->assertSessionHasNoErrors();

        return Configuration::firstOrFail();
    }

    public function test_optional_sag_allowance_saves_reloads_preserves_zero_and_can_be_cleared(): void
    {
        $this->put(route('engineering.update', $this->project), $this->payload() + ['sag_allowance_percent' => '2.5'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertEquals(2.5, $configuration->settings['engineering']['sag_allowance_percent']);
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Optional sag allowance (%)')
            ->assertSee('name="sag_allowance_percent"', false)->assertSee('value="2.5"', false);
        $payload = array_replace($this->payload(), ['revision' => $configuration->revision]);
        $this->put(route('engineering.update', $this->project), $payload)->assertSessionHasNoErrors();
        $this->assertEquals(2.5, $configuration->fresh()->settings['engineering']['sag_allowance_percent']);

        foreach (['0' => 0, '' => null] as $entered => $expected) {
            $payload = array_replace($this->payload(), ['revision' => $configuration->fresh()->revision, 'sag_allowance_percent' => (string) $entered]);
            $this->put(route('engineering.update', $this->project), $payload)->assertSessionHasNoErrors();
            $this->assertSame($expected, $configuration->fresh()->settings['engineering']['sag_allowance_percent']);
        }
    }

    public function test_sag_allowance_is_optional_for_drafts_and_invalid_percentages_are_rejected(): void
    {
        $configuration = $this->saveConfiguration();
        $this->assertArrayNotHasKey('sag_allowance_percent', $configuration->settings['engineering']);
        foreach ([-1, 100.1, 'invalid'] as $invalid) {
            foreach (['draft', null] as $action) {
                $this->put(route('engineering.update', $this->project), array_replace($this->payload(),
                    ['revision' => $configuration->revision, 'save_action' => $action, 'sag_allowance_percent' => $invalid]))
                    ->assertSessionHasErrors('sag_allowance_percent');
            }
        }
        $this->put(route('engineering.update', $this->project), ['revision' => $configuration->revision,
            'save_action' => 'draft', 'sag_allowance_percent' => 3.5, 'reason' => 'Admin reviewed sag allowance'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(3.5, $configuration->fresh()->settings['engineering']['sag_allowance_percent']);
        $this->assertNull($configuration->fresh()->approved_by);
        $this->put(route('engineering.update', $this->project), ['revision' => $configuration->fresh()->revision,
            'save_action' => 'draft', 'sag_allowance_percent' => '', 'reason' => 'Admin disabled sag allowance'])
            ->assertSessionHasNoErrors();
        $this->assertNull($configuration->fresh()->settings['engineering']['sag_allowance_percent']);
    }

    public function test_admin_can_configure_once_and_render_saved_engineering_settings(): void
    {
        $this->get(route('engineering.index'))->assertOk()->assertSee('HAZECO');
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Load-demand mapping');
        $configuration = $this->saveConfiguration();
        $this->assertSame($this->admin->id, $configuration->approved_by);
        $this->assertSame(50, $configuration->settings['mapping']['table_values']['SAI_Control']['Frequency']);
        $this->assertSame(0.4, $configuration->settings['mapping']['table_values']['InstPrimaryTransformers']['SpecNomKv']);
        $this->assertSame($this->template->id, $configuration->settings['template_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.configuration_changed']);
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Approved revision 1')->assertSee('approved-100');
        $this->put(route('mdb-workflow.config.update', $this->project), ['revision' => 1])->assertSessionHasErrors('configuration');
        $this->assertSame(1, $configuration->fresh()->revision);
    }

    public function test_admin_can_edit_an_incomplete_reference_import_without_inventing_missing_values(): void
    {
        $configuration = Configuration::create(['project_id' => $this->project->id, 'revision' => 1,
            'settings' => ['frequency_hz' => 60, 'engineering' => ['version' => 1], 'reference_import' => [
                'status' => 'needs_review', 'consistent_parameter_count' => 12,
                'sources' => [['file' => 'approved-reference.mdb', 'sha256' => str_repeat('a', 64)]],
                'conflicts' => [['field' => 'Frequency', 'values' => [['value' => 60, 'files' => ['approved-reference.mdb']]]]],
                'missing' => [['field' => 'secondary_voltage_kv', 'reason' => 'Not established by the supplied equipment references.']],
                'equipment_references' => ['conductors' => ['ANT'], 'transformers' => ['100 KVA'], 'configurations' => ['observed-line']],
                'notes' => ['Reference IDs do not establish installed library revisions.'],
            ]]]);
        $references = ['conductors' => ['ANT', 'WASP'], 'transformers' => ['100 KVA'], 'configurations' => ['observed-line']];
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 1,
            'epsg' => '', 'frequency_hz' => 50, 'secondary_voltage_kv' => '', 'load_mode' => '', 'blank_consumers_zero' => '',
            'equipment_references_json' => json_encode($references), 'reason' => 'Reviewing imported reference observations'])->assertRedirect()->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertSame(2, $configuration->revision);
        $this->assertNull($configuration->epsg);
        $this->assertNull($configuration->approved_by);
        $this->assertNull($configuration->approved_at);
        $this->assertNull($configuration->settings['secondary_voltage_kv']);
        $this->assertNull($configuration->settings['engineering']['blank_consumers_zero']);
        $this->assertNull($configuration->load_assumptions['mode']);
        $this->assertSame($references, $configuration->settings['reference_import']['equipment_references']);
        $this->assertSame('approved-reference.mdb', $configuration->settings['reference_import']['sources'][0]['file']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.configuration_draft_saved']);
        $this->assertDatabaseCount('mdb_workflow_templates', 1);
        $this->assertSame(['ANT'], $this->template->fresh()->metadata['equipment_references']['conductors']);
        $response = $this->get(route('engineering.edit', $this->project))->assertOk()
            ->assertSee('Draft revision 2')->assertSee('approved-reference.mdb')->assertSee('Conflicting observations')
            ->assertSee('secondary_voltage_kv')->assertSee('WASP')->assertSee('Save draft');
        $this->assertMatchesRegularExpression('/name="load_mode"[^>]*><option value="" selected/', $response->getContent());
        $this->assertMatchesRegularExpression('/name="blank_consumers_zero"[^>]*><option value="" selected/', $response->getContent());
        $this->post(route('mdb-builder.export', $this->workspace()), ['revision' => 1])->assertSessionHasErrors('engineering');
    }

    public function test_draft_save_revokes_existing_approval_and_exports_without_changing_historical_configuration(): void
    {
        $configuration = $this->saveConfiguration();
        $revision = app(SnapshotService::class)->capture($this->batch->fresh(), $this->admin->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $export = ExportJob::create(['batch_id' => $this->batch->id, 'revision_id' => $revision->id, 'template_id' => $this->template->id,
            'requested_by' => $this->admin->id, 'status' => 'queued', 'idempotency_key' => str_repeat('d', 64), 'mapping_version' => 'synergee-reviewed-v1', 'template_version' => '1']);
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 1,
            'frequency_hz' => 60, 'approved' => 1, 'reason' => 'Engineering revision pending review'])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertSame(2, $configuration->revision);
        $this->assertNull($configuration->approved_by);
        $this->assertNull($configuration->approved_at);
        $this->assertSame(60, $configuration->settings['frequency_hz']);
        $this->assertArrayNotHasKey('reviewed_by', $configuration->settings['mapping']);
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertNotNull($export->fresh()->superseded_at);
        $this->assertSame(50, $revision->fresh()->snapshot['configuration']['settings']['frequency_hz']);
    }

    public function test_draft_save_validates_supplied_parameters_and_json_without_requiring_missing_settings(): void
    {
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 0,
            'frequency_hz' => 55, 'secondary_voltage_kv' => -1, 'rates' => ['rs' => ['power_factor' => 1.2]],
            'reason' => 'Review imported engineering settings'])->assertSessionHasErrors(['frequency_hz', 'secondary_voltage_kv', 'rates.rs.power_factor']);
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 0,
            'equipment_references_json' => '{"conductors":"ANT","transformers":[],"configurations":[]}',
            'reason' => 'Review imported engineering settings'])->assertSessionHasErrors('equipment_references_json');
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 0,
            'mapping_json' => '[]', 'reason' => 'Review imported engineering settings'])->assertSessionHasErrors('mapping_json');
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->with([], 4326)->andThrow(new \RuntimeException('Target CRS must be projected with metre units'));
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 0,
            'epsg' => 4326, 'reason' => 'Review imported engineering settings'])->assertSessionHasErrors('epsg');
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_approved_save_retains_exact_observed_reference_edits_separately_from_template_libraries(): void
    {
        $data = $this->payload();
        $references = ['conductors' => ['DOG', ' DOG'], 'transformers' => ['100 KVA'], 'configurations' => ['observed-line']];
        $data['equipment_references_json'] = json_encode($references);
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasNoErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertSame($references, $configuration->settings['reference_import']['equipment_references']);
        $this->assertSame($this->admin->id, $configuration->approved_by);
        $this->assertSame(['ANT'], $this->template->fresh()->metadata['equipment_references']['conductors']);
        $data['revision'] = 1;
        $data['equipment_references_json'] = '{"conductors":"DOG","transformers":[],"configurations":[]}';
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('equipment_references_json');
        $this->assertSame(1, $configuration->fresh()->revision);
    }

    public function test_draft_revision_protection_and_admin_access_match_the_approval_path(): void
    {
        $data = ['save_action' => 'draft', 'revision' => 0, 'frequency_hz' => 50, 'reason' => 'Populate verified reference values'];
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasNoErrors();
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('revision');
        $this->assertSame(1, Configuration::firstOrFail()->revision);
        $user = User::factory()->create(['role' => UserRole::ProjectManager]);
        $user->givePermissionTo('MdbWorkflow:Configure');
        $this->actingAs($user)->put(route('engineering.update', $this->project), array_replace($data, ['revision' => 1]))->assertForbidden();
        $this->assertSame(1, Configuration::firstOrFail()->revision);
    }

    public function test_non_admin_cannot_read_or_update_settings_even_with_configure_permission(): void
    {
        $user = User::factory()->create(['role' => UserRole::ProjectManager]);
        $user->givePermissionTo('MdbWorkflow:Configure');
        $this->actingAs($user);
        $this->get(route('engineering.index'))->assertForbidden();
        $this->get(route('engineering.edit', $this->project))->assertForbidden();
        $this->put(route('engineering.update', $this->project), $this->payload())->assertForbidden();
        $this->get(route('mdb-workflow.config', $this->project))->assertForbidden();
        $this->put(route('mdb-workflow.config.update', $this->project), [])->assertForbidden();
        $this->post(route('mdb-workflow.templates.store'), [])->assertForbidden();
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_invalid_rates_or_unapproved_equipment_cannot_be_approved(): void
    {
        $data = $this->payload();
        $data['rates']['rs']['power_factor'] = 1.2;
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('rates.rs.power_factor');
        $data = $this->payload();
        $data['transformer_types_json'] = '{"100":"unknown-type"}';
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('transformer_types_json');
        $data = $this->payload();
        $data['configuration_id'] = 'unapproved-line';
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('configuration_id');
        $data = $this->payload();
        $data['mapping_json'] = '[]';
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('mapping_json');
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_stale_admin_form_cannot_overwrite_saved_configuration(): void
    {
        $configuration = $this->saveConfiguration();
        $this->put(route('engineering.update', $this->project), $this->payload())->assertSessionHasErrors('revision');
        $this->assertSame(1, $configuration->fresh()->revision);
    }

    public function test_imported_draft_can_be_edited_without_rechecking_unchanged_declared_crs(): void
    {
        Configuration::create(['project_id' => $this->project->id, 'epsg' => 32643, 'revision' => 1,
            'settings' => ['frequency_hz' => 60, 'engineering' => ['version' => 1], 'reference_import' => ['status' => 'needs_review']],
            'approved_by' => null, 'approved_at' => null]);
        $this->mock(ProjectionService::class)->shouldNotReceive('projectMany');
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 1, 'epsg' => 32643,
            'frequency_hz' => 60, 'reason' => 'Edit imported settings without approving the CRS'])->assertSessionHasNoErrors();
        $this->assertNull(Configuration::firstOrFail()->approved_by);
    }

    public function test_invalid_crs_or_revoked_template_cannot_be_approved(): void
    {
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->with([], 4326)->andThrow(new \RuntimeException('Target CRS must be projected with metre units'));
        $data = array_replace($this->payload(), ['epsg' => 4326]);
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasErrors('epsg');
        $this->template->update(['active' => false]);
        $this->put(route('engineering.update', $this->project), $this->payload())->assertSessionHasErrors('template_id');
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_incomplete_draft_remains_readable_with_automatic_demand_enabled(): void
    {
        $configuration = $this->saveConfiguration()->toArray();
        $snapshot = ['configuration' => $configuration, 'transformers' => [['sections' => [['phases' => null, 'consumers' => [['category' => 'rs', 'count' => 3, 'demand' => null]]]]]]];
        $effective = app(EngineeringConfiguration::class)->apply($snapshot);
        $this->assertNull($effective['transformers'][0]['sections'][0]['consumers'][0]['demand']);
    }

    public function test_configuration_update_revokes_approval_and_preserves_old_snapshot(): void
    {
        $configuration = $this->saveConfiguration();
        $this->batch->refresh();
        $revision = app(SnapshotService::class)->capture($this->batch, $this->admin->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $export = ExportJob::create(['batch_id' => $this->batch->id, 'revision_id' => $revision->id, 'template_id' => $this->template->id,
            'requested_by' => $this->admin->id, 'status' => 'queued', 'idempotency_key' => str_repeat('c', 64), 'mapping_version' => 'synergee-reviewed-v1', 'template_version' => '1']);
        $data = array_replace($this->payload(), ['revision' => 1, 'frequency_hz' => 60]);
        $this->put(route('engineering.update', $this->project), $data)->assertSessionHasNoErrors();
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertNotNull($export->fresh()->superseded_at);
        $this->assertSame(50, $revision->fresh()->snapshot['configuration']['settings']['frequency_hz']);
        $this->assertSame(60, $configuration->fresh()->settings['frequency_hz']);
    }

    public function test_selected_template_is_pinned_even_when_another_template_is_registered(): void
    {
        $this->saveConfiguration();
        $new = $this->template->replicate();
        $new->version = '2';
        $new->save();
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $this->assertSame($this->template->id, $snapshot['template']['id']);
        $this->template->update(['active' => false]);
        $this->assertNull(app(SnapshotService::class)->snapshot($this->batch->fresh())['template']);
    }

    public function test_approved_rates_apply_without_overwriting_original_counts_or_explicitly_assuming_blanks(): void
    {
        $configuration = $this->saveConfiguration()->toArray();
        $snapshot = ['configuration' => $configuration, 'transformers' => [['sections' => [[
            'phases' => ['R', 'B'], 'equipment_ref' => null, 'consumers' => [['category' => 'rs', 'count' => 5], ['category' => 'sc', 'count' => null]],
        ]], 'entry_rows' => [['consumers' => ['rs' => 5, 'sc' => null]]]]]];
        $effective = app(EngineeringConfiguration::class)->apply($snapshot);
        $section = $effective['transformers'][0]['sections'][0];
        $this->assertSame('approved-line', $section['equipment_ref']);
        $phases = $section['consumers'][0]['demand']['phase_values'];
        $this->assertSame(3, $phases['R']['customers']);
        $this->assertSame(2, $phases['B']['customers']);
        $this->assertEqualsWithDelta(7.2, $phases['R']['kw'], 1e-8);
        $this->assertEqualsWithDelta(5.4, $phases['R']['kvar'], 1e-8);
        $this->assertArrayNotHasKey('Y', $phases);
        $this->assertNull($section['consumers'][1]['count']);
        $this->assertSame($snapshot['transformers'][0]['entry_rows'], $effective['transformers'][0]['entry_rows']);
        $snapshot['configuration']['settings']['engineering']['blank_consumers_zero'] = 1;
        $this->assertSame(0, app(EngineeringConfiguration::class)->apply($snapshot)['transformers'][0]['sections'][0]['consumers'][1]['count']);
    }

    public function test_legacy_export_cannot_bypass_missing_central_configuration(): void
    {
        $workspace = $this->workspace();
        $this->post(route('mdb-builder.export', $workspace), ['revision' => 1])->assertSessionHasErrors('engineering');
    }

    public function test_configuration_change_during_legacy_generation_withholds_the_file(): void
    {
        $configuration = $this->saveConfiguration();
        $workspace = $this->workspace();
        $path = Storage::disk('local')->path('withheld.mdb');
        $this->mock(TransformerMdbWriter::class)->shouldReceive('write')->once()->andReturnUsing(function () use ($configuration, $path) {
            Storage::disk('local')->put('withheld.mdb', 'synthetic output');
            $configuration->increment('revision');

            return $path;
        });
        $this->post(route('mdb-builder.export', $workspace), ['revision' => 1])->assertSessionHasErrors('engineering');
        Storage::disk('local')->assertMissing('withheld.mdb');
    }

    public function test_legacy_builder_uses_central_voltage_projection_libraries_and_demand_instead_of_local_values(): void
    {
        $configuration = $this->saveConfiguration();
        $network = app(TransformerMdbNetworkService::class)->build($this->workspace(), $configuration);
        $this->assertSame('WGS 1984 UTM Zone 42N', $network['tables']['SAI_Control'][0]['ProjectionFile']);
        $this->assertSame(50, $network['tables']['SAI_Control'][0]['Frequency']);
        $this->assertSame(11.0, $network['tables']['InstFeeders'][0]['NominalKvll']);
        $this->assertSame('approved-100', $network['tables']['InstPrimaryTransformers'][0]['TransformerType']);
        $load = $network['tables']['Loads'][0];
        $this->assertSame(9, $load['Phase1Customers']);
        $this->assertEqualsWithDelta(21.6, $load['Phase1Kw'], 1e-8);
        $this->assertSame($this->template->id, $network['engineering_template_id']);
    }

    private function workspace(): TransformerMdbProject
    {
        return TransformerMdbProject::create(['feeder_id' => $this->feeder->id, 'created_by' => $this->admin->id, 'transformer_code' => 'T-1',
            'survey_date' => '2026-10-01', 'header' => ['capacity_kva' => 100, 'substation' => 'Grid'], 'solar' => [], 'revision' => 1,
            'rows' => [
                ['se' => 'S', 'date' => '2026-10-01', 'gps_waypoint' => '001', 'phase' => 'RYB', 'conductor_r' => 'A', 'conductor_y' => 'A', 'conductor_b' => 'A', 'conductor_neutral' => 'A'],
                ['se' => 'E', 'date' => '2026-10-01', 'gps_waypoint' => '002', 'latitude' => 30.464641, 'longitude' => 71.917066, 'consumers' => array_fill_keys(EngineeringConfiguration::CATEGORIES, 1)],
            ], 'export_settings' => ['utm_zone' => 43, 'frequency' => 60, 'nominal_kv' => 4.16, 'transformer_type' => 'wrong', 'configuration_id' => 'wrong',
                'transformer_waypoints' => '001', 'transformer_latitude' => 30.464656, 'transformer_longitude' => 71.917033, 'engineering_reviewed' => false]]);
    }
}
