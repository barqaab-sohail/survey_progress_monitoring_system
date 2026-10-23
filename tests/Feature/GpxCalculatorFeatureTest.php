<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GpxCalculation;
use App\Models\GridStation;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class GpxCalculatorFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('route.gpx', '<gpx version="1.1"><rte><rtept lat="34" lon="73"><ele>100</ele></rtept><rtept lat="34" lon="73.001"><ele>120</ele></rtept></rte></gpx>');
    }

    private function settings(array $extra = []): array
    {
        return array_replace(['long_span_m' => 500, 'span_sequences' => '', 'project_id' => '', 'feeder_id' => ''], $extra);
    }

    public function test_upload_render_preview_save_and_kmz_export_recalculate_on_server(): void
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($user)->get(route('gpx-calculator.index'))->assertOk()->assertSee('GPX Length');
        $this->post(route('gpx-calculator.store'), ['gpx' => $this->file()])->assertSessionHasNoErrors()->assertRedirect();
        $record = GpxCalculation::firstOrFail();
        $this->assertFalse($record->settings['include_elevation']);
        $this->assertFalse($record->settings['include_sag']);
        $this->get(route('gpx-calculator.show', $record))->assertOk()->assertSee('Span sequences');
        $this->postJson(route('gpx-calculator.preview', $record), $this->settings(['include_elevation' => true]))->assertOk()->assertJsonPath('complete', true);
        $this->assertFalse($record->fresh()->settings['include_elevation']); // Preview does not write.
        $this->post(route('gpx-calculator.update', $record), $this->settings(['include_elevation' => 1, 'action' => 'save', 'final_m' => 999999]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($record->fresh()->settings['include_elevation']);
        $this->assertLessThan(100, $record->fresh()->result['final_m']);
        $response = $this->post(route('gpx-calculator.update', $record), $this->settings(['include_sag' => 1, 'include_elevation' => 1,
            'span_sequences' => 'p1,p2', 'connections_confirmed' => 1, 'supports_confirmed' => 1, 'default_sag' => 2, 'action' => 'export']));
        $response->assertOk()->assertDownload('route.kmz')->assertHeader('content-type', 'application/vnd.google-earth.kmz');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->getFromName('doc.kml'));
        } finally {
            $zip->close();
            unlink($path);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'gpx.kmz_exported', 'auditable_id' => $record->id]);
    }

    public function test_access_is_authenticated_active_and_private_to_owner_or_administrator(): void
    {
        $this->get(route('gpx-calculator.index'))->assertRedirect(route('login'));
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $owner = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $other = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $viewer = User::factory()->create(['role' => UserRole::ManagementViewer]);
        $this->actingAs($owner)->post(route('gpx-calculator.store'), ['gpx' => $this->file()])->assertSessionHasNoErrors();
        $record = GpxCalculation::firstOrFail();
        $this->actingAs($other)->get(route('gpx-calculator.show', $record))->assertNotFound();
        $this->postJson(route('gpx-calculator.preview', $record), $this->settings())->assertNotFound();
        $this->post(route('gpx-calculator.update', $record), $this->settings(['action' => 'export']))->assertNotFound();
        $this->actingAs($viewer)->get(route('gpx-calculator.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('gpx-calculator.show', $record))->assertOk();
        $owner->update(['status' => 'inactive']);
        $this->get(route('gpx-calculator.show', $record))->assertRedirect(route('login'));
    }

    public function test_upload_and_settings_validation_block_unsafe_or_incomplete_exports(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->post(route('gpx-calculator.store'), ['gpx' => UploadedFile::fake()->createWithContent('unsafe.gpx', '<!DOCTYPE gpx><gpx/>')])->assertSessionHasErrors('gpx');
        $this->post(route('gpx-calculator.store'), ['gpx' => UploadedFile::fake()->createWithContent('unsafe.txt', '<gpx/>')])->assertSessionHasErrors('gpx');
        $this->post(route('gpx-calculator.store'), ['gpx' => $this->file()])->assertSessionHasNoErrors();
        $record = GpxCalculation::firstOrFail();
        $this->postJson(route('gpx-calculator.preview', $record), $this->settings(['include_sag' => true, 'default_sag' => 2]))->assertUnprocessable()->assertJsonValidationErrors('supports_confirmed');
        $this->postJson(route('gpx-calculator.preview', $record), $this->settings(['sag_overrides' => ['p1_p2' => -2]]))->assertUnprocessable()->assertJsonValidationErrors('sag_overrides.p1_p2');
        $this->post(route('gpx-calculator.store'), ['gpx' => UploadedFile::fake()->createWithContent('points.gpx', '<gpx><wpt lat="34" lon="73"/><wpt lat="34" lon="73.001"/></gpx>')])->assertSessionHasNoErrors();
        $waypoints = GpxCalculation::latest('id')->firstOrFail();
        $this->post(route('gpx-calculator.update', $waypoints), $this->settings(['action' => 'export']))->assertSessionHasErrors('span_sequences');
        $this->postJson(route('gpx-calculator.preview', $waypoints), $this->settings(['span_sequences' => 'p1,p2', 'connections_confirmed' => true, 'include_elevation' => true]))->assertOk()->assertJsonPath('complete', false)->assertJsonPath('blocked_spans', 1);
    }

    public function test_project_and_feeder_links_follow_existing_team_and_current_allocation_access(): void
    {
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $operator = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $project = Project::create(['code' => 'GPX', 'name' => 'GPX project', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'Grid']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F-GPX', 'feeder_name' => 'Linked feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'GPX-S', 'name' => 'Survey team', 'status' => 'active']);
        $links = ['project_id' => $project->id, 'feeder_id' => $feeder->id];
        $this->actingAs($operator)->post(route('gpx-calculator.store'), $links + ['gpx' => $this->file()])->assertSessionHasErrors('project_id');
        $team->members()->attach($operator);
        $this->post(route('gpx-calculator.store'), $links + ['gpx' => $this->file()])->assertSessionHasErrors('project_id');
        $assignment = FeederAssignment::create(['feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'assigned_by' => $admin->id, 'status' => 'active', 'start_date' => today()->subDay()]);
        $this->post(route('gpx-calculator.store'), $links + ['gpx' => $this->file()])->assertSessionHasNoErrors();
        $record = GpxCalculation::firstOrFail();
        $this->assertSame($feeder->id, $record->feeder_id);
        $this->get(route('gpx-calculator.show', $record))->assertOk();
        $assignment->update(['end_date' => today()->subDay()]);
        $this->get(route('gpx-calculator.show', $record))->assertNotFound();
        $this->actingAs($admin)->get(route('gpx-calculator.show', $record))->assertOk();
    }
}
