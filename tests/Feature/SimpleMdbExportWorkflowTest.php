<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\Mdb\GenerateMdbExport;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\Consumer;
use App\Models\Mdb\NetworkSection;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Mdb\Waypoint;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\Exporter;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\ReadbackVerifier;
use App\Services\Mdb\SchemaTemplate;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\WindowsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SimpleMdbExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private SurveyBatch $batch;

    private Configuration $configuration;

    private Template $template;

    private NetworkSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([GenerateMdbExport::class]);
        $this->actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'SIMPLE', 'name' => 'Simple export fixture', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'G']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subdivision->id,
            'grid_station_id' => $grid->id, 'feeder_code' => 'F1', 'feeder_name' => 'Survey feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'T', 'name' => 'Team', 'status' => 'active']);
        $this->template = app(SchemaTemplate::class)->ensure($this->actor);
        $this->configuration = Configuration::create(['project_id' => $project->id, 'revision' => 1, 'epsg' => null,
            'settings' => ['simple_mdb_generation' => true, 'template_id' => $this->template->id], 'load_assumptions' => []]);
        $this->batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2026-10-17', 'created_by' => $this->actor->id]);
        $gpx = $this->source('gpx', '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1" />');
        $pdf = $this->source('pdf', '%PDF-1.4 survey fixture');
        $root = Waypoint::create(['batch_id' => $this->batch->id, 'source_file_id' => $gpx->id, 'name' => '001', 'latitude' => 31.7, 'longitude' => 73.5]);
        $end = Waypoint::create(['batch_id' => $this->batch->id, 'source_file_id' => $gpx->id, 'name' => '002', 'latitude' => 31.701, 'longitude' => 73.5]);
        $transformer = NetworkTransformer::create(['batch_id' => $this->batch->id, 'code' => 'T1', 'capacity_kva' => null, 'source_waypoint_id' => $root->id,
            'header' => ['manually_verified' => true, 'feeder_identifier' => 'F1', 'feeder_name' => 'Survey feeder', 'substation_identifier' => 'G1',
                'substation_name' => 'Survey substation', 'location' => 'Verified location', 'survey_date' => '2026-10-17', 'team_group' => 'S1', 'inspector' => 'Survey inspector',
                'pages' => [['source_pdf_id' => $pdf->id, 'page' => 1, 'role' => 'header', 'confirmed' => true]]]]);
        $this->section = NetworkSection::create(['transformer_id' => $transformer->id, 'start_waypoint_id' => $root->id, 'end_waypoint_id' => $end->id,
            'start_reference' => '001', 'end_reference' => '002', 'start_source_file_id' => $gpx->id, 'end_source_file_id' => $gpx->id,
            'phases' => [], 'conductors' => [], 'source_pdf_id' => $pdf->id, 'source_page' => 1, 'source_row' => '1', 'original_entry' => ['manually_verified' => true]]);
        Consumer::create(['section_id' => $this->section->id, 'category' => 'rs', 'count' => 3, 'original_value' => '3']);
        $this->approve();
    }

    private function source(string $kind, string $bytes): SourceFile
    {
        $path = 'fixture/source.'.$kind;
        Storage::disk('local')->put($path, $bytes);

        return SourceFile::create(['batch_id' => $this->batch->id, 'kind' => $kind, 'disk' => 'local', 'path' => $path, 'original_name' => 'source.'.$kind,
            'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'mime' => $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml',
            'uploaded_by' => $this->actor->id, 'status' => 'ready', 'metadata' => $kind === 'pdf' ? ['page_count' => 1] : []]);
    }

    private function approve(): void
    {
        $this->batch->refresh();
        $revision = app(SnapshotService::class)->capture($this->batch, $this->actor->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $this->batch->refresh();
    }

    private function disableSimpleMode(): void
    {
        $this->configuration->update(['settings' => array_replace($this->configuration->settings, ['simple_mdb_generation' => false]),
            'revision' => $this->configuration->revision + 1]);
    }

    /** Mock bytes verify export lifecycle only; genuine schema readback has its own integration coverage. */
    private function lifecycleWorker(?callable $duringExport = null): void
    {
        $this->mock(Exporter::class)->shouldReceive('export')->once()->andReturnUsing(function ($payload, $template, $output) use ($duringExport) {
            $this->assertTrue($payload['settings']['simple_mdb_generation']);
            $this->assertNull($template->approved_by);
            file_put_contents($output, 'lifecycle fixture artifact');
            $duringExport?->__invoke();

            return ['log' => 'Lifecycle fixture'];
        });
        $this->mock(ReadbackVerifier::class)->shouldReceive('verify')->once()->andReturn(['readable' => true, 'synergee_validation' => 'pending']);
    }

    public function test_simple_request_and_worker_allow_unapproved_schema_and_missing_engineering_without_fabricating_values(): void
    {
        $validation = app(NetworkValidator::class)->validate($this->batch);
        $this->assertTrue($validation['valid'], json_encode($validation['errors']));
        $this->assertContains('missing_electrical_setting', array_column($validation['warnings'], 'code'));
        $this->lifecycleWorker();
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        $payload = json_decode(Storage::disk('local')->get($job->payload_path), true);
        $this->assertTrue($payload['settings']['simple_mdb_generation']);
        $this->assertNull($payload['tables']['SAI_Control'][0]['Frequency'] ?? null);
        $this->assertNull($payload['tables']['Node'][0]['X']);
        $this->assertNull($payload['tables']['Loads'][0]['Phase1Kva']);
        $this->assertArrayNotHasKey('SurveyRows', $payload['tables']);
        $exports->run($job->id);
        $this->assertSame('generated', $job->fresh()->status);
        $this->assertNull($this->configuration->fresh()->approved_by);
        $this->assertNull($this->template->fresh()->approved_by);
        Bus::assertDispatchedTimes(GenerateMdbExport::class, 1);
    }

    public function test_confirmed_simple_settings_populate_transformer_loads_and_section_connection(): void
    {
        $settings = $this->configuration->settings;
        $settings['engineering'] = ['configuration_id' => 'line-type', 'transformer_types' => ['100' => 'transformer-100']];
        $settings['mapping']['table_values']['InstSection']['LoadConnectionPhaseGnd'] = 1;
        $settings['simple_model_review'] = ['reviewed_by' => $this->actor->id, 'reviewed_at' => now()->toIso8601String()];
        $this->configuration->update(['settings' => $settings, 'load_assumptions' => [
            'mode' => 'category_rates', 'rates' => ['rs' => ['kva' => 3, 'power_factor' => 0.8]],
            'method' => 'Department category rates', 'evidence' => 'Verified department standard',
        ]]);
        $this->section->transformer()->update(['capacity_kva' => 100]);
        $this->section->update(['phases' => ['R', 'Y', 'B']]);
        $this->batch->increment('revision');
        $this->approve();
        $revision = $this->batch->approvedRevision;
        $payload = app(ExportPayloadBuilder::class)->build($revision, $this->template, null, 'confirmed-simple');
        $this->assertSame('transformer-100', $payload['tables']['InstPrimaryTransformers'][0]['TransformerType']);
        foreach ($payload['tables']['InstSection'] as $section) {
            $this->assertSame(1, $section['LoadConnectionPhaseGnd']);
        }
        $load = $payload['tables']['Loads'][0];
        $this->assertEqualsWithDelta(2, $load['Phase1Kva'], 1e-8);
        $this->assertNull($load['Phase1Kw']);
        $this->assertNull($load['Phase1Kvar']);
        $this->assertEquals(1, $load['Phase1Customers']);
    }

    public function test_simple_request_keeps_essential_connectivity_validation(): void
    {
        $this->section->update(['end_waypoint_id' => $this->section->start_waypoint_id, 'end_reference' => '001']);
        $this->batch->increment('revision');
        $this->approve();
        $this->mock(Exporter::class)->shouldNotReceive('export');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A section cannot connect a waypoint to itself.');
        app(ExportService::class)->request($this->batch, $this->actor);
    }

    public function test_switching_simple_mode_off_before_worker_supersedes_the_queued_export(): void
    {
        $this->mock(Exporter::class)->shouldNotReceive('export');
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        $this->disableSimpleMode();
        $exports->run($job->id);
        $this->assertSame('superseded', $job->fresh()->status);
        $this->assertNull($job->fresh()->output_path);
        $this->assertSame(0, $job->fresh()->attempts);
    }

    public function test_switching_simple_mode_off_during_worker_withholds_the_artifact(): void
    {
        $this->lifecycleWorker(fn () => $this->disableSimpleMode());
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        $exports->run($job->id);
        $this->assertSame('superseded', $job->fresh()->status);
        $this->assertNull($job->fresh()->output_path);
        $this->assertNull($job->fresh()->generated_at);
        Storage::disk('local')->assertMissing('mdb-workflow/exports/'.$job->id.'/approved.mdb');
    }

    public function test_native_worker_generates_the_minimal_simple_configuration_with_the_complete_approved_schema(): void
    {
        $worker = new WindowsExporter;
        if (! $worker->configured()) {
            $this->markTestSkipped('Windows Access DAO writer is unavailable.');
        }
        $this->app->instance(Exporter::class, $worker);
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        $exports->run($job->id);
        $job->refresh();
        $this->assertSame('generated', $job->status);
        $this->assertSame(50, $job->readback['schema_table_count']);
        $this->assertSame(41, $job->readback['schema_relationship_count']);
        $this->assertTrue($job->readback['mapped_values_verified']);
        $this->assertTrue($job->readback['references_verified']);
        $this->assertSame($job->output_sha256, hash('sha256', Storage::disk('local')->get($job->output_path)));
        $payload = json_decode(Storage::disk('local')->get($job->payload_path), true);
        $this->assertSame('G1', $payload['tables']['InstFeeders'][0]['SubstationId']);
        $this->assertNull($payload['tables']['InstFeeders'][0]['NominalKvll'] ?? null);
        $this->assertNull($this->template->fresh()->approved_by);
    }
}
