<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\Mdb\GenerateMdbExport;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\DisabledExporter;
use App\Services\Mdb\Exporter;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\ReadbackVerifier;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\WindowsExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private SurveyBatch $batch;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([GenerateMdbExport::class]);
        $this->actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'EX', 'name' => 'Export fixture', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'G']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'EX', 'feeder_name' => 'EX', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'T', 'name' => 'T', 'status' => 'active']);
        Storage::disk('local')->put('fixture/template.mdb', file_get_contents(resource_path('mdb/synergee-empty.mdb')));
        $this->template = Template::create(['code' => 'fixture', 'version' => 'schema-fixture', 'disk' => 'local', 'path' => 'fixture/template.mdb', 'sha256' => hash_file('sha256', resource_path('mdb/synergee-empty.mdb')), 'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'metadata' => [], 'approved_by' => $this->actor->id, 'approved_at' => now('UTC'), 'active' => true]);
        $this->batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'survey_date' => '2025-08-23', 'created_by' => $this->actor->id]);
        $sourceBytes = '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1" />';
        Storage::disk('local')->put('fixture/source.gpx', $sourceBytes);
        SourceFile::create(['batch_id' => $this->batch->id, 'kind' => 'gpx', 'disk' => 'local', 'path' => 'fixture/source.gpx', 'original_name' => 'source.gpx', 'sha256' => hash('sha256', $sourceBytes), 'bytes' => strlen($sourceBytes), 'mime' => 'application/gpx+xml', 'uploaded_by' => $this->actor->id, 'status' => 'ready']);
        $revision = app(SnapshotService::class)->capture($this->batch, $this->actor->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $payload = $this->mock(ExportPayloadBuilder::class);
        $payload->shouldReceive('build')->andReturnUsing(fn ($revision, $template, $transformerId, $key) => [
            'payload_version' => 1, 'mapping_version' => ExportPayloadBuilder::VERSION, 'idempotency_key' => $key,
            'revision' => ['sha256' => $revision->sha256], 'template' => ['sha256' => $template->sha256],
            'settings' => [], 'tables' => array_fill_keys(ExportPayloadBuilder::TABLES, []),
        ]);
    }

    public function test_duplicate_requests_bind_one_export_and_one_job_to_approval(): void
    {
        $exports = app(ExportService::class);
        $first = $exports->request($this->batch, $this->actor);
        $second = $exports->request($this->batch, $this->actor);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('mdb_workflow_exports', 1);
        $this->assertSame($this->batch->approved_revision_id, $first->revision_id);
        Storage::disk('local')->assertExists($first->payload_path);
        $this->assertSame($first->payload_sha256, hash('sha256', Storage::disk('local')->get($first->payload_path)));
        Bus::assertDispatchedTimes(GenerateMdbExport::class, 1);
    }

    public function test_disabled_worker_retains_payload_without_claiming_mdb_and_retry_reuses_id(): void
    {
        $this->app->instance(Exporter::class, new DisabledExporter);
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        $exports->run($job->id);
        $job->refresh();
        $this->assertSame('worker_not_configured', $job->status);
        $this->assertNull($job->output_path);
        $this->assertNull($job->generated_at);
        $this->assertStringContainsString('not configured', $job->log);
        $retried = $exports->retry($job, $this->actor);
        $this->assertSame($job->id, $retried->id);
        $this->assertSame('queued', $retried->status);
        $this->assertDatabaseCount('mdb_workflow_exports', 1);
    }

    public function test_failure_is_retryable_and_duplicate_delivery_does_not_repeat_success(): void
    {
        $worker = new class implements Exporter
        {
            public int $calls = 0;

            public function configured(): bool
            {
                return true;
            }

            public function export(array $payload, Template $template, string $outputPath): array
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('Transient worker failure');
                }
                file_put_contents($outputPath, 'lifecycle mock artifact');

                return ['log' => 'Lifecycle mock'];
            }
        };
        $this->app->instance(Exporter::class, $worker);
        $this->mock(ReadbackVerifier::class)->shouldReceive('verify')->once()->andReturn(['readable' => true, 'synergee_validation' => 'pending']);
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        try {
            $exports->run($job->id);
            $this->fail('First worker failure must be retried by the queue.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Transient worker failure', $exception->getMessage());
        }
        $this->assertSame('failed', $job->fresh()->status);
        $exports->run($job->id);
        $exports->run($job->id);
        $this->assertSame(2, $worker->calls);
        $this->assertSame('generated', $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->attempts);
        Storage::disk('local')->assertExists($job->fresh()->output_path);
    }

    public function test_edit_revokes_approval_and_prevents_queued_output(): void
    {
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        app(SnapshotService::class)->invalidate($this->batch);
        $exports->run($job->id);
        $this->assertSame('superseded', $job->fresh()->status);
        $this->assertNull($job->fresh()->output_path);
        $this->expectException(ValidationException::class);
        $exports->retry($job->fresh(), $this->actor);
    }

    public function test_tampered_payload_hash_blocks_worker(): void
    {
        $worker = $this->mock(Exporter::class);
        $worker->shouldNotReceive('export');
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        Storage::disk('local')->put($job->payload_path, '{"tampered":true}');
        $this->expectException(ValidationException::class);
        $exports->run($job->id);
    }

    public function test_source_bytes_changed_after_approval_block_export(): void
    {
        Storage::disk('local')->put('fixture/source.gpx', '<gpx altered="true" />');
        $this->expectException(ValidationException::class);
        app(ExportService::class)->request($this->batch, $this->actor);
    }

    public function test_source_tampering_after_queueing_marks_failure_before_worker_execution(): void
    {
        $worker = $this->mock(Exporter::class);
        $worker->shouldNotReceive('export');
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch, $this->actor);
        Storage::disk('local')->put('fixture/source.gpx', '<gpx altered="true" />');
        try {
            $exports->run($job->id);
            $this->fail('A changed approved source must block export.');
        } catch (ValidationException $exception) {
            $this->assertSame('failed', $job->fresh()->status);
            $this->assertNull($job->fresh()->output_path);
        }
    }

    public function test_genuine_dao_writer_reopens_original_one_transformer_fixture(): void
    {
        $writer = new WindowsExporter;
        if (! $writer->configured()) {
            $this->markTestSkipped('Windows Access DAO writer is unavailable.');
        }
        $directory = Storage::disk('local')->path('worker-fixture-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/Mdb/noor-pur-writer.json')), true, 512, JSON_THROW_ON_ERROR);
        $payload['template']['sha256'] = $this->template->sha256;
        try {
            $result = $writer->export($payload, $this->template, $directory.'/result.mdb');
            $readback = (new ReadbackVerifier)->verify($directory.'/result.mdb', $payload, $result);
            $this->assertSame(['SAI_Control' => 1, 'Node' => 22, 'InstFeeders' => 1, 'InstSection' => 20, 'InstPrimaryTransformers' => 1, 'Loads' => 19], $readback['counts']);
            $this->assertSame('pending', $readback['synergee_validation']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_a_renamed_sqlite_or_unverified_artifact_is_rejected(): void
    {
        $path = Storage::disk('local')->path('fake.mdb');
        file_put_contents($path, str_pad('SQLite format 3', 4096, "\0"));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a Microsoft Access Jet MDB');
        (new ReadbackVerifier)->verify($path, ['tables' => []], []);
    }
}
