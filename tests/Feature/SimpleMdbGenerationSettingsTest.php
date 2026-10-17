<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
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
use App\Models\User;
use App\Services\Mdb\GenerationMode;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\SnapshotService;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimpleMdbGenerationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'HAZECO', 'name' => 'HAZECO', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    public function test_generation_mode_defaults_off_and_accepts_saved_and_frozen_configurations(): void
    {
        foreach ([null, [], ['settings' => []], ['settings' => ['simple_mdb_generation' => 'false']], ['settings' => ['simple_mdb_generation' => false]]] as $configuration) {
            $this->assertFalse(GenerationMode::simple($configuration));
        }
        $configuration = new Configuration(['settings' => ['simple_mdb_generation' => true]]);
        $this->assertTrue(GenerationMode::simple($configuration));
        $this->assertTrue(GenerationMode::simple($configuration->toArray()));
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Simple MDB Generation')->assertSee('Current setting: OFF');
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
    }

    public function test_admin_can_enable_without_engineering_parameters_and_disable_again(): void
    {
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 0, 'simple_mdb_generation' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertSame(['simple_mdb_generation' => true], $configuration->settings);
        $this->assertSame(1, $configuration->revision);
        $this->assertNull($configuration->epsg);
        $this->assertNull($configuration->approved_by);
        $this->assertNull($configuration->approved_at);
        $this->assertNull($configuration->load_assumptions);
        $this->get(route('engineering.edit', $this->project))->assertOk()->assertSee('Current setting: ON');

        $this->put(route('engineering.mode.update', $this->project), ['revision' => 1, 'simple_mdb_generation' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(GenerationMode::simple($configuration->fresh()));
        $this->assertSame(2, $configuration->fresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'mdb.generation_mode_changed']);
        $audit = AuditLog::where('action', 'mdb.generation_mode_changed')->latest('id')->firstOrFail();
        $this->assertTrue($audit->old_values['settings']['simple_mdb_generation']);
        $this->assertFalse($audit->new_values['settings']['simple_mdb_generation']);
    }

    public function test_non_admin_with_direct_configuration_permission_cannot_change_mode(): void
    {
        foreach ([UserRole::ProjectManager, UserRole::MdbGenerator, UserRole::ManagementViewer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $user->givePermissionTo('MdbWorkflow:Configure');
            $this->actingAs($user)->put(route('engineering.mode.update', $this->project), ['revision' => 0, 'simple_mdb_generation' => 1])->assertForbidden();
        }
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mdb.generation_mode_changed']);
    }

    public function test_mode_updates_require_explicit_boolean_and_current_revision(): void
    {
        foreach ([['revision' => 0], ['revision' => 0, 'simple_mdb_generation' => 'on'], ['simple_mdb_generation' => 1]] as $payload) {
            $this->put(route('engineering.mode.update', $this->project), $payload)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('mdb_workflow_configurations', 0);
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 0, 'simple_mdb_generation' => 1])->assertSessionHasNoErrors();
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 0, 'simple_mdb_generation' => 0])->assertSessionHasErrors('revision');
        $configuration = Configuration::firstOrFail();
        $this->assertSame(1, $configuration->revision);
        $this->assertTrue(GenerationMode::simple($configuration));
        $this->assertSame(1, AuditLog::where('action', 'mdb.generation_mode_changed')->count());
    }

    public function test_mode_update_preserves_engineering_values_loads_and_existing_approval(): void
    {
        $configuration = Configuration::create(['project_id' => $this->project->id, 'epsg' => 32643, 'revision' => 4,
            'settings' => ['frequency_hz' => 60, 'engineering' => ['version' => 1, 'supply_conductor_id' => 'ANT'],
                'mapping' => ['table_values' => ['SAI_Control' => ['Frequency' => 60]]], 'reference_import' => ['sources' => [['file' => 'approved.mdb']]]],
            'load_assumptions' => ['mode' => 'manual', 'evidence' => 'Approved measurement records'],
            'approved_by' => $this->admin->id, 'approved_at' => now('UTC')]);
        $before = $configuration->toArray();
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 4, 'simple_mdb_generation' => 1,
            'frequency_hz' => 50, 'epsg' => 4326, 'approved_by' => null])->assertSessionHasNoErrors();
        $after = $configuration->fresh()->toArray();
        $expectedSettings = $before['settings'];
        $expectedSettings['simple_mdb_generation'] = true;
        $this->assertSame($expectedSettings, $after['settings']);
        foreach (['epsg', 'load_assumptions', 'approved_by', 'approved_at'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertSame(5, $after['revision']);
    }

    public function test_saving_the_unchanged_mode_does_not_advance_configuration_or_batch_revisions(): void
    {
        $configuration = Configuration::create(['project_id' => $this->project->id, 'revision' => 3, 'settings' => ['simple_mdb_generation' => false]]);
        $batch = $this->batch();
        $revision = app(SnapshotService::class)->capture($batch->fresh(), $this->admin->id);
        $batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 3, 'simple_mdb_generation' => 0])->assertSessionHasNoErrors();
        $this->assertSame(3, $configuration->fresh()->revision);
        $this->assertSame($revision->id, $batch->fresh()->approved_revision_id);
        $this->assertSame(1, $batch->fresh()->revision);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mdb.generation_mode_changed']);
    }

    public function test_mode_change_invalidates_affected_approvals_exports_and_retains_frozen_mode(): void
    {
        $template = $this->template();
        $configuration = Configuration::create(['project_id' => $this->project->id, 'revision' => 1, 'settings' => ['simple_mdb_generation' => false]]);
        $batch = $this->batch();
        $revision = app(SnapshotService::class)->capture($batch->fresh(), $this->admin->id);
        $batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $export = ExportJob::create(['batch_id' => $batch->id, 'revision_id' => $revision->id, 'template_id' => $template->id,
            'requested_by' => $this->admin->id, 'status' => 'queued', 'idempotency_key' => str_repeat('c', 64),
            'mapping_version' => 'synergee-reviewed-v1', 'template_version' => '1']);
        $this->put(route('engineering.mode.update', $this->project), ['revision' => 1, 'simple_mdb_generation' => 1])->assertSessionHasNoErrors();
        $this->assertNull($batch->fresh()->approved_revision_id);
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->revision);
        $this->assertNotNull($export->fresh()->superseded_at);
        $this->assertFalse(GenerationMode::simple($revision->fresh()->snapshot['configuration']));
        $this->assertTrue(GenerationMode::simple($configuration->fresh()));
    }

    public function test_engineering_draft_and_approved_saves_preserve_admin_generation_mode(): void
    {
        Configuration::create(['project_id' => $this->project->id, 'revision' => 1, 'settings' => ['simple_mdb_generation' => true]]);
        $this->put(route('engineering.update', $this->project), ['save_action' => 'draft', 'revision' => 1,
            'frequency_hz' => 60, 'reason' => 'Review reference values', 'simple_mdb_generation' => 0])->assertSessionHasNoErrors();
        $this->assertTrue(GenerationMode::simple(Configuration::firstOrFail()));
        $template = $this->template();
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->with([], 32643)->andReturn([]);
        $this->put(route('engineering.update', $this->project), [
            'revision' => 2, 'epsg' => 32643, 'crs_name' => 'WGS 1984 UTM Zone 43N', 'frequency_hz' => 60,
            'nominal_voltage_kv' => 11, 'secondary_voltage_kv' => 0.4, 'template_id' => $template->id,
            'conductor_library_revision' => 'test-v1', 'transformer_library_revision' => 'test-v1',
            'configuration_id' => 'approved-line', 'supply_conductor_id' => 'ANT', 'secondary_conductor_id' => 'ANT',
            'phase_spacing_cm' => 100, 'neutral_spacing_cm' => 75, 'conductor_height_m' => 8,
            'pole_height_unit' => 'ft', 'two_digit_year_start' => 2000, 'load_mode' => 'manual',
            'load_evidence' => 'Verified test measurement records', 'blank_consumers_zero' => 0,
            'mapping_json' => json_encode(['version' => 'synergee-reviewed-v1', 'table_values' => [
                'SAI_Control' => (object) [], 'Node' => (object) [], 'InstFeeders' => (object) [], 'InstSection' => (object) [],
                'InstPrimaryTransformers' => ['UseInstanceImpedance' => 0], 'Loads' => (object) [],
            ], 'networks' => (object) []]),
            'transformer_types_json' => '{"100":"approved-100"}', 'approved' => 1, 'reason' => 'Approve test configuration',
            'simple_mdb_generation' => 0,
        ])->assertSessionHasNoErrors();
        $configuration = Configuration::firstOrFail();
        $this->assertTrue(GenerationMode::simple($configuration));
        $this->assertSame($this->admin->id, $configuration->approved_by);
        $this->assertSame(3, $configuration->revision);
    }

    private function template(): Template
    {
        return Template::create(['code' => 'fixture', 'version' => '1', 'disk' => 'local', 'path' => 'template.mdb', 'sha256' => str_repeat('a', 64),
            'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'active' => true, 'approved_by' => $this->admin->id, 'approved_at' => now('UTC'),
            'metadata' => ['equipment_references' => ['conductors' => ['ANT'], 'transformers' => ['approved-100'], 'configurations' => ['approved-line']]]]);
    }

    private function batch(): SurveyBatch
    {
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $subdivision = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'G']);
        $feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F', 'feeder_name' => 'Feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'T', 'name' => 'T', 'status' => 'active']);

        return SurveyBatch::create(['project_id' => $this->project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2026-10-17', 'created_by' => $this->admin->id]);
    }
}
