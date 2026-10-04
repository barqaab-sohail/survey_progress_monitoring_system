<?php

namespace Tests\Feature;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\MdbDailyEntryItem;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\SurveyProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MdbVerificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrading_populated_legacy_mdb_schema_preserves_data_and_restores_login_dashboard_and_review(): void
    {
        $internal = Organization::create(['name' => 'Internal Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $external = Organization::create(['name' => 'Third Party Verification', 'type' => 'third_party', 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::SuperAdmin]);
        $surveyor = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::SurveyTeamLeader]);
        $mdbUser = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::MdbTeamUser]);
        $reviewer = User::factory()->create(['organization_id' => $external->id, 'role' => UserRole::MdbProcessingUser]);
        $project = Project::create(['code' => 'P1', 'name' => 'Legacy Project', 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '5901', 'feeder_name' => 'Legacy MDB Feeder', 'total_transformers' => 100, 'baseline_pending' => false, 'processing_required' => true, 'status' => 'active']);
        $surveyTeam = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST-01', 'name' => 'Survey Team', 'status' => 'active']);
        $surveyTeam->members()->attach($surveyor->id, ['is_leader' => true]);
        $mdbTeam = MdbTeam::create(['project_id' => $project->id, 'code' => 'MDB-01', 'name' => 'MDB Team', 'status' => 'active']);
        $mdbTeam->members()->attach($mdbUser->id);
        FeederAssignment::create(['feeder_id' => $feeder->id, 'survey_team_id' => $surveyTeam->id, 'assigned_by' => $admin->id, 'start_date' => today(), 'status' => 'active']);
        $survey = app(SurveyProgressService::class)->create($surveyor, $surveyTeam, [
            'entry_date' => today()->subDays(12)->toDateString(),
            'items' => [['feeder_id' => $feeder->id, 'transformers_surveyed' => 80, 'drive_url' => 'https://drive.google.com/drive/folders/legacy-survey']],
        ]);
        app(SurveyProgressService::class)->verify($mdbUser, $survey->items->first());

        // Reproduce the deployed pre-verification schema only in the guarded memory database.
        $migration = require database_path('migrations/2026_10_04_000100_add_mdb_verification_workflow.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('mdb_daily_entry_items', 'status'));
        $this->assertFalse(Schema::hasTable('mdb_verification_histories'));
        $entryIds = [];
        $itemIds = [];
        foreach ([15, 50] as $index => $quantity) {
            $timestamp = now()->subDays(10 - $index)->setTime(9, 30)->format('Y-m-d H:i:s');
            $entryIds[] = $entryId = DB::table('mdb_daily_entries')->insertGetId([
                'entry_date' => substr($timestamp, 0, 10),
                'mdb_team_id' => $mdbTeam->id,
                'entered_by' => $mdbUser->id,
                'remarks' => 'Legacy batch '.($index + 1),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $itemIds[] = DB::table('mdb_daily_entry_items')->insertGetId([
                'mdb_daily_entry_id' => $entryId,
                'feeder_id' => $feeder->id,
                'mdb_files_created' => $quantity,
                'drive_url' => 'https://drive.google.com/drive/folders/legacy-mdb-'.($index + 1),
                'remarks' => 'Legacy MDB evidence '.($index + 1),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
        $originalEntries = DB::table('mdb_daily_entries')->whereIn('id', $entryIds)->orderBy('id')->get();
        $originalItems = DB::table('mdb_daily_entry_items')->whereIn('id', $itemIds)->orderBy('id')->get();

        $migration->up();
        $this->assertTrue(Schema::hasColumns('mdb_daily_entry_items', ['status', 'verified_by', 'verified_at', 'return_reason', 'resubmitted_at']));
        $this->assertTrue(Schema::hasTable('mdb_verification_histories'));
        $this->assertDatabaseCount('mdb_daily_entries', 2);
        $this->assertDatabaseCount('mdb_daily_entry_items', 2);
        $this->assertDatabaseCount('mdb_verification_histories', 0);
        foreach ($originalEntries as $original) {
            $this->assertSame((array) $original, (array) DB::table('mdb_daily_entries')->where('id', $original->id)->first());
        }
        foreach ($originalItems as $original) {
            $upgraded = (array) DB::table('mdb_daily_entry_items')->where('id', $original->id)->first();
            foreach ((array) $original as $field => $value) {
                $this->assertSame($value, $upgraded[$field]);
            }
            $this->assertSame('submitted', $upgraded['status']);
            foreach (['verified_by', 'verified_at', 'return_reason', 'resubmitted_at'] as $field) {
                $this->assertNull($upgraded[$field]);
            }
            $this->assertSame(SurveyItemStatus::Submitted, MdbDailyEntryItem::findOrFail($original->id)->status);
        }

        $this->post('/login', ['email' => $mdbUser->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($mdbUser);
        $this->get('/')->assertOk()->assertSee('MDB creation status');
        $this->get('/mdb')->assertOk()->assertSee('Legacy MDB evidence 1')->assertSee('Legacy MDB evidence 2');
        $this->get('/mdb/create')->assertOk()->assertSee('5901');
        $summary = app(DashboardService::class)->summary();
        $this->assertSame(65, $summary['mdb_created']);
        $this->assertSame(65, $summary['mdb_verification_pending']);
        $this->assertSame(0, $summary['mdb_verified']);
        $this->actingAs($reviewer)->get('/mdb-verification')->assertOk()->assertSee('Legacy MDB evidence 1')->assertSee('Legacy MDB evidence 2');
        $this->post('/mdb-verification/items/'.$itemIds[0].'/verify')->assertRedirect();
        $this->assertDatabaseHas('mdb_daily_entry_items', ['id' => $itemIds[0], 'status' => 'verified', 'verified_by' => $reviewer->id, 'mdb_files_created' => 15]);
        $this->assertDatabaseHas('mdb_verification_histories', ['mdb_daily_entry_item_id' => $itemIds[0], 'acted_by' => $reviewer->id, 'action' => 'verified', 'quantity_snapshot' => 15]);
        $this->assertSame(65, app(DashboardService::class)->summary()['mdb_created']);
        $this->assertSame(15, app(DashboardService::class)->summary()['mdb_verified']);
    }
}
