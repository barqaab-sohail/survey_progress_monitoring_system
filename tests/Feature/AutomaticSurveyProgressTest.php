<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SurveyProgress\RunSurveyProgress;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyProgress\Connection;
use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use App\Models\SurveyProgress\Transcription;
use App\Models\User;
use App\Services\SurveyProgress\DriveClient;
use App\Services\SurveyProgress\LengthService;
use App\Services\SurveyProgress\SourceParser;
use App\Services\SurveyProgress\Synchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutomaticSurveyProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['survey_progress.project_code' => 'HAZECO-TDL', 'survey_progress.expected_folders' => 1, 'survey_progress.automatic' => true]);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'HAZECO-TDL', 'name' => 'HAZECO', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '407', 'feeder_name' => 'NAWAN SHEHR', 'total_transformers' => 10, 'baseline_pending' => false, 'status' => 'active']);
        Connection::create(['id' => 1, 'connected_by' => $this->admin->id, 'token' => ['access_token' => 'test-only', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addHour()->timestamp, 'scope' => DriveClient::SCOPE]]);
        $this->actingAs($this->admin);
    }

    private function files(int $quantity = 5): array
    {
        return [['id' => 'pdf-source', 'name' => 'A_10102026_0'.$quantity.'.pdf', 'mimeType' => 'application/pdf', 'md5Checksum' => md5('pdf'), 'size' => 3], ['id' => 'gpx-source', 'name' => 'A_10102026_0'.$quantity.'.gpx', 'mimeType' => 'application/gpx+xml', 'md5Checksum' => md5('gpx'), 'size' => 3]];
    }

    private function mockDrive(array $files): void
    {
        $mock = $this->mock(DriveClient::class);
        $mock->shouldReceive('children')->with(config('survey_progress.parent_folder'))->andReturn([['id' => 'folder-407', 'name' => '000407-NAWAN-SHEHR', 'mimeType' => 'application/vnd.google-apps.folder']]);
        $mock->shouldReceive('inventory')->with('folder-407')->andReturn($files);
    }

    public function test_daily_sync_is_idempotent_and_never_updates_existing_feeder_baseline(): void
    {
        $this->mockDrive($this->files());
        foreach (range(1, 2) as $i) {
            app(Synchronizer::class)->sync(Run::create(['type' => 'sync']));
        }
        $state = ProgressFeeder::where('feeder_id', $this->feeder->id)->firstOrFail();
        $this->assertSame(5, $state->surveyed_count);
        $this->assertSame('folder-407', $state->folder_id);
        $this->assertSame(['A' => 5], $state->aggregates['by_group']);
        $this->assertSame(10, $this->feeder->fresh()->total_transformers);
        $this->assertDatabaseCount('survey_progress_files', 2);
        $this->assertDatabaseCount('survey_progress_runs', 2);
        $this->assertDatabaseCount('survey_daily_entries', 0);
        $this->assertDatabaseCount('mdb_workflow_batches', 0);
    }

    public function test_failed_listing_retains_last_successful_count_and_marks_length_stale(): void
    {
        ProgressFeeder::create(['feeder_id' => $this->feeder->id, 'folder_id' => 'folder-407', 'surveyed_count' => 7, 'synced_at' => now(), 'length_status' => 'completed', 'calculated_at' => now(), 'confirmed_km' => 3]);
        $mock = $this->mock(DriveClient::class);
        $mock->shouldReceive('children')->andReturn([['id' => 'folder-407', 'name' => '407-NAWAN', 'mimeType' => 'application/vnd.google-apps.folder']]);
        $mock->shouldReceive('inventory')->andThrow(new \RuntimeException('Unavailable'));
        app(Synchronizer::class)->sync($run = Run::create(['type' => 'sync']));
        $state = ProgressFeeder::first();
        $this->assertSame(7, $state->surveyed_count);
        $this->assertSame('failed', $state->sync_status);
        $this->assertSame('stale', $state->length_status);
        $this->assertSame('partial', $run->fresh()->status);
    }

    public function test_source_removal_reconciles_counts_without_deleting_sync_history(): void
    {
        $this->mockDrive($this->files());
        app(Synchronizer::class)->sync($first = Run::create(['type' => 'sync']));
        $state = ProgressFeeder::first();
        $state->update(['calculated_at' => now(), 'length_status' => 'completed', 'confirmed_km' => 1.2]);
        $this->mockDrive([$this->files()[0]]);
        app(Synchronizer::class)->sync(Run::create(['type' => 'sync']));
        $this->assertSame(0, $state->fresh()->surveyed_count);
        $this->assertSame('stale', $state->fresh()->length_status);
        $this->assertSame(5, $first->fresh()->result['folders'][0]['count']);
        $this->assertDatabaseCount('survey_progress_files', 2);
        $this->assertSame(1, $state->files()->where('active', true)->count());
    }

    public function test_parent_folder_failure_keeps_counts_and_excludes_previous_lengths(): void
    {
        $state = ProgressFeeder::create(['feeder_id' => $this->feeder->id, 'surveyed_count' => 5, 'synced_at' => now(), 'calculated_at' => now(), 'length_status' => 'completed', 'confirmed_km' => 1.2]);
        $this->mock(DriveClient::class)->shouldReceive('children')->andThrow(new \RuntimeException('Parent unavailable'));
        app(Synchronizer::class)->sync($run = Run::create(['type' => 'sync']));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(5, $state->fresh()->surveyed_count);
        $this->assertSame('stale', $state->fresh()->length_status);
    }

    public function test_manual_entry_is_disabled_and_admin_only_actions_reject_managers(): void
    {
        $this->post(route('survey.store'), [])->assertStatus(409);
        $this->get(route('survey.create'))->assertRedirect(route('survey-progress.index'));
        $manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $this->actingAs($manager)->post(route('survey-progress.length', $this->feeder))->assertForbidden();
        $this->get(route('survey-progress.transcriptions', $this->feeder))->assertForbidden();
        $this->post(route('survey-progress.sync'))->assertForbidden();
    }

    public function test_admin_can_queue_length_without_using_mdb_queue(): void
    {
        Queue::fake();
        ProgressFeeder::create(['feeder_id' => $this->feeder->id, 'folder_id' => 'folder-407', 'synced_at' => now()]);
        $this->post(route('survey-progress.length', $this->feeder))->assertRedirect();
        Queue::assertPushed(RunSurveyProgress::class, fn ($job) => $job->connection === 'survey-progress' && $job->queue === 'survey-progress');
        $this->assertDatabaseCount('mdb_workflow_exports', 0);
    }

    public function test_drive_listing_paginates_and_refreshes_only_separate_progress_token(): void
    {
        $connection = Connection::first();
        $token = $connection->token;
        $token['expires_at'] = 0;
        $connection->update(['token' => $token]);
        $this->admin->google_drive_token = ['access_token' => 'existing-mdb-token'];
        $this->admin->save();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'new-progress-token', 'expires_in' => 3600]);
            }

            return ($request->data()['pageToken'] ?? null) === 'page-2' ? Http::response(['files' => [['id' => 'second', 'name' => 'second.gpx', 'mimeType' => 'application/gpx+xml']]]) : Http::response(['nextPageToken' => 'page-2', 'files' => [['id' => 'first', 'name' => 'first.pdf', 'mimeType' => 'application/pdf']]]);
        });
        $this->assertCount(2, app(DriveClient::class)->children('folder-407'));
        $this->assertSame('existing-mdb-token', $this->admin->fresh()->google_drive_token['access_token']);
        $this->assertSame('new-progress-token', $connection->fresh()->token['access_token']);
    }

    public function test_reviewed_transcriptions_are_versioned_and_bound_to_current_sources(): void
    {
        $this->mockDrive($this->files(1));
        app(Synchronizer::class)->sync(Run::create(['type' => 'sync']));
        $url = route('survey-progress.transcriptions.store', $this->feeder);
        $data = ['survey_key' => 'A_10102026_1', 'version' => 0, 'pdf_checksum' => md5('pdf'), 'gpx_checksum' => md5('gpx'), 'records' => '11131222104,11131222104,01101026001,1,1', 'reviewed' => true];
        $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull(Transcription::first()->reviewed_at);
        $this->post($url, $data)->assertStatus(409);
        $data['version'] = 1;
        $data['reviewed'] = false;
        $data['records'] = '11131222104,104,001,1,1';
        $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('survey_progress_transcriptions', 2);
        $this->assertNull(Transcription::latest('version')->first()->reviewed_at);
        $data['version'] = 2;
        $data['pdf_checksum'] = md5('changed');
        $this->post($url, $data)->assertStatus(409);
    }

    public function test_dashboard_renders_filename_counts_and_excludes_stale_lengths(): void
    {
        ProgressFeeder::create(['feeder_id' => $this->feeder->id, 'surveyed_count' => 5, 'synced_at' => now(), 'length_status' => 'stale', 'confirmed_km' => 123]);
        $response = $this->get(route('survey-progress.index'))->assertOk()->assertSee('Reported Surveyed Transformers')->assertSee('Calculate LT Length');
        $response->assertViewHas('summary', fn ($s) => $s['surveyed'] === 5 && (float) $s['confirmed_km'] === 0.0 && $s['remaining'] === 5);
    }

    public function test_missing_reviewed_transcription_excludes_scanned_pdf_from_confirmed_calculation(): void
    {
        $files = $this->files(1);
        $files[0]['size'] = 8;
        $files[0]['md5Checksum'] = md5('%PDF-1.4');
        $this->mockDrive($files);
        app(Synchronizer::class)->sync(Run::create(['type' => 'sync']));
        app(DriveClient::class)->shouldReceive('download')->andReturnUsing(function ($file, $path) {
            file_put_contents($path, $file['mimeType'] === 'application/pdf' ? '%PDF-1.4' : 'gpx');
        });
        $state = ProgressFeeder::first();
        $run = Run::create(['type' => 'length', 'progress_feeder_id' => $state->id]);
        app(LengthService::class)->calculate($run);
        $this->assertNull($state->fresh()->confirmed_km);
        $this->assertContains('reviewed_transcription_required', array_column($run->fresh()->result['issues'], 'code'));
        $this->assertSame('needs_review', $run->fresh()->status);
    }

    public function test_reviewed_pdf_network_calculates_confirmed_3d_length_without_mdb_writes(): void
    {
        $bytes = ['pdf-source' => '%PDF-1.4', 'gpx-source' => '<gpx><wpt lat="34" lon="73.001"><name>01101026001</name><ele>150</ele></wpt></gpx>', 'kmz-source' => 'kmz-fixture'];
        $files = $this->files(1);
        $files[] = ['id' => 'kmz-source', 'name' => 'NAWAN SHEHR (000407).kmz', 'mimeType' => 'application/vnd.google-earth.kmz'];
        foreach ($files as &$file) {
            $file['size'] = strlen($bytes[$file['id']]);
            $file['md5Checksum'] = md5($bytes[$file['id']]);
        } unset($file);
        $this->mockDrive($files);
        app(Synchronizer::class)->sync(Run::create(['type' => 'sync']));
        app(DriveClient::class)->shouldReceive('download')->andReturnUsing(function ($file, $path) use ($bytes) {
            file_put_contents($path, $bytes[$file['id']]);
        });
        $this->partialMock(SourceParser::class)->shouldReceive('kmz')->andReturn(['roots' => ['11131222104' => [['id' => '11131222104', 'lat' => 34.0, 'lon' => 73.0, 'elevation' => 100.0]]], 'issues' => []]);
        $this->post(route('survey-progress.transcriptions.store', $this->feeder), ['survey_key' => 'A_10102026_1', 'version' => 0, 'pdf_checksum' => md5($bytes['pdf-source']), 'gpx_checksum' => md5($bytes['gpx-source']), 'records' => '11131222104,11131222104,01101026001,1,1', 'reviewed' => true])->assertSessionHasNoErrors();
        $state = ProgressFeeder::first();
        $run = Run::create(['type' => 'length', 'progress_feeder_id' => $state->id]);
        app(LengthService::class)->calculate($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertGreaterThan(0.1, (float) $state->fresh()->confirmed_km);
        $this->assertSame(0, $state->fresh()->unresolved_spans);
        $this->assertDatabaseCount('mdb_workflow_batches', 0);
        $this->assertDatabaseCount('mdb_workflow_exports', 0);
        $this->assertDatabaseCount('survey_daily_entries', 0);
    }
}
