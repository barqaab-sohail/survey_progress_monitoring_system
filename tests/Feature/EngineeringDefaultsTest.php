<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\EngineeringPreset;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\EngineeringDefaults;
use App\Services\Mdb\ProjectionService;
use Database\Seeders\EngineeringDefaultsSeeder;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngineeringDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MdbWorkflowPermissionSeeder::class, EngineeringDefaultsSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'DEFAULTS', 'name' => 'Defaults test', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $this->actingAs($this->admin);
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->with([], 32643)->andReturn([]);
    }

    private function preset(): EngineeringPreset
    {
        return EngineeringPreset::firstOrFail();
    }

    private function updateData(array $payload): array
    {
        return ['revision' => $this->preset()->revision, 'name' => 'Reviewed defaults', 'enabled' => 1,
            'configuration_json' => json_encode($payload), 'reason' => 'Review reusable engineering defaults'];
    }

    public function test_seed_has_nine_sources_and_reseeding_preserves_admin_changes_and_disabled_state(): void
    {
        $preset = $this->preset();
        $this->assertCount(9, $preset->evidence['sources']);
        $this->assertSame(133, $preset->evidence['consistent_parameter_count']);
        $this->assertContains('INSULATED ANT', $preset->configuration['equipment_references']['conductors']);
        $this->assertContains('Loads.observed_kva_per_customer', array_column($preset->evidence['conflicts'], 'field'));
        $payload = $preset->configuration;
        $payload['settings']['frequency_hz'] = 50;
        $preset->update(['name' => 'Custom library', 'enabled' => false, 'revision' => 7, 'configuration' => $payload]);
        $before = $preset->fresh()->getRawOriginal();
        $this->seed(EngineeringDefaultsSeeder::class);
        $this->assertSame($before, $preset->fresh()->getRawOriginal());
        $this->assertDatabaseCount('mdb_engineering_presets', 1);
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_new_configuration_copies_defaults_as_unapproved_without_inventing_missing_values(): void
    {
        $configuration = app(EngineeringDefaults::class)->initialize($this->project, $this->admin);
        $this->assertSame(32643, $configuration->epsg);
        $this->assertSame(60, $configuration->settings['frequency_hz']);
        $this->assertSame(11, $configuration->settings['nominal_voltage_kv']);
        $this->assertNull($configuration->settings['secondary_voltage_kv']);
        $this->assertNull($configuration->settings['conductor_library_revision']);
        $this->assertNull($configuration->load_assumptions['mode']);
        $this->assertNull($configuration->approved_by);
        $this->assertNull($configuration->approved_at);
        $this->assertSame([], $configuration->settings['mapping']['networks']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.engineering_defaults_loaded']);
        $this->assertSame($configuration->id, app(EngineeringDefaults::class)->initialize($this->project, $this->admin)->id);
        $this->assertDatabaseCount('mdb_workflow_configurations', 1);
    }

    public function test_existing_custom_configuration_is_never_merged_and_library_edits_do_not_change_it(): void
    {
        $configuration = Configuration::create(['project_id' => $this->project->id, 'revision' => 4, 'epsg' => 32642,
            'settings' => ['frequency_hz' => 50, 'simple_mdb_generation' => true, 'mapping' => ['custom' => 'retained']],
            'load_assumptions' => [], 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $before = $configuration->fresh()->getRawOriginal();
        app(EngineeringDefaults::class)->initialize($this->project, $this->admin);
        $this->get(route('engineering.edit', $this->project))->assertOk();
        $payload = $this->preset()->configuration;
        $payload['settings']['frequency_hz'] = 50;
        $this->put(route('engineering.library.update'), $this->updateData($payload))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before, $configuration->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mdb.engineering_defaults_loaded']);
    }

    public function test_preview_is_read_only_and_project_draft_keeps_source_evidence_and_user_override(): void
    {
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Predefined engineering values')->assertSee('INSULATED ANT');
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
        $this->put(route('engineering.update', $this->project), ['revision' => 0, 'save_action' => 'draft',
            'frequency_hz' => 50, 'reason' => 'Choose project frequency explicitly'])->assertSessionHasNoErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertSame(50, $configuration->settings['frequency_hz']);
        $this->assertCount(9, $configuration->settings['reference_import']['sources']);
        $this->assertSame(1, $configuration->settings['defaults_library']['revision']);
        $this->assertNull($configuration->approved_by);
    }

    public function test_library_edit_is_audited_versioned_and_retains_immutable_source_evidence(): void
    {
        $payload = $this->preset()->configuration;
        $payload['settings']['frequency_hz'] = 50;
        $evidence = $this->preset()->evidence;
        $data = $this->updateData($payload);
        $this->get(route('engineering.library'))->assertOk()->assertSee('Conflicting observations')->assertSee('Mosa PakT-2241017145.mdb');
        $this->put(route('engineering.library.update'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $this->preset()->revision);
        $this->assertSame($evidence, $this->preset()->evidence);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.engineering_library_updated']);
        $this->put(route('engineering.library.update'), $data)->assertSessionHasErrors('revision');
        $configuration = app(EngineeringDefaults::class)->initialize($this->project, $this->admin);
        $this->assertSame(50, $configuration->settings['frequency_hz']);
        $this->assertSame(2, $configuration->settings['defaults_library']['revision']);
    }

    public function test_disabled_library_does_not_initialize_and_only_admin_can_read_or_edit(): void
    {
        $this->preset()->update(['enabled' => false]);
        $this->assertNull(app(EngineeringDefaults::class)->initialize($this->project, $this->admin));
        $this->actingAs(User::factory()->create(['role' => UserRole::ProjectManager]));
        $this->get(route('engineering.library'))->assertForbidden();
        $this->put(route('engineering.library.update'), $this->updateData($this->preset()->configuration))->assertForbidden();
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_library_rejects_schema_changes_network_data_bad_types_and_approval_bypass(): void
    {
        foreach (['unknown_table', 'unknown_field', 'coordinate', 'network', 'voltage_type', 'approval', 'bad_rate'] as $case) {
            $payload = $this->preset()->configuration;
            match ($case) {
                'unknown_table' => $payload['settings']['mapping']['table_values']['ExtraTable'] = [],
                'unknown_field' => $payload['settings']['mapping']['table_values']['InstSection']['ExtraField'] = 1,
                'coordinate' => $payload['settings']['mapping']['table_values']['Node']['X'] = 123,
                'network' => $payload['settings']['mapping']['networks']['foreign-transformer'] = [],
                'voltage_type' => $payload['settings']['mapping']['table_values']['InstFeeders']['NominalKvll'] = 'invalid',
                'approval' => $payload['settings']['mapping']['reviewed_by'] = $this->admin->id,
                'bad_rate' => $payload['load_assumptions']['rates']['rs'] = ['kva' => 3, 'power_factor' => 2],
            };
            $this->put(route('engineering.library.update'), $this->updateData($payload))->assertSessionHasErrors();
        }
        $this->assertSame(1, $this->preset()->revision);
    }

    public function test_new_mdb_workflow_loads_defaults_and_later_batches_preserve_custom_project_settings(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['drive_storage.enabled' => false]);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'Grid']);
        $feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F', 'feeder_name' => 'Feeder', 'status' => 'active', 'total_transformers' => 0]);
        $team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'T', 'name' => 'Team', 'status' => 'active']);
        $upload = fn () => ['project_id' => $this->project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2026-10-19', 'survey_pdf' => UploadedFile::fake()->createWithContent('survey.pdf', '%PDF-1.4 fixture'),
            'gps_gpx' => UploadedFile::fake()->createWithContent('survey.gpx', '<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1" creator="test"><wpt lat="34" lon="73"><name>001</name></wpt></gpx>')];
        $this->post(route('mdb-workflow.store'), $upload())->assertSessionHasNoErrors()->assertRedirect();
        $configuration = Configuration::firstOrFail();
        $this->assertSame(60, $configuration->settings['frequency_hz']);
        $configuration->update(['settings' => ['frequency_hz' => 50, 'custom' => 'keep'], 'revision' => 2]);
        $before = $configuration->fresh()->getRawOriginal();
        $this->post(route('mdb-workflow.store'), $upload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before, $configuration->fresh()->getRawOriginal());
        $this->assertDatabaseCount('mdb_workflow_batches', 2);
        $this->assertDatabaseCount('mdb_workflow_configurations', 1);
    }
}
