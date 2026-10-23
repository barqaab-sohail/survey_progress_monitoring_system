<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\VerificationDecision;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\DataEntryMdbExport;
use App\Services\Mdb\DataEntryPayload;
use App\Services\Mdb\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\DataEntrySnapshot;
use Tests\TestCase;

class DataEntryMdbExportTest extends TestCase
{
    use RefreshDatabase;

    private function batch(): SurveyBatch
    {
        $actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($actor);
        $project = Project::create(['code' => 'PDF', 'name' => 'PDF export', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'G']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id,
            'grid_station_id' => $grid->id, 'feeder_code' => '10333', 'feeder_name' => 'Feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Team', 'status' => 'active']);
        $batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2025-08-08', 'created_by' => $actor->id, 'revision' => 1, 'staged_workflow' => true, 'survey_status' => 'approved', 'status' => 'approved']);
        $snapshot = DataEntrySnapshot::make();
        $revision = Revision::create(['batch_id' => $batch->id, 'number' => 1, 'snapshot' => $snapshot,
            'sha256' => app(SnapshotService::class)->hash($snapshot), 'created_by' => $actor->id, 'frozen_at' => now()]);
        VerificationDecision::create(['batch_id' => $batch->id, 'revision_id' => $revision->id, 'decided_by' => $actor->id, 'decision' => 'approve']);
        $batch->update(['approved_revision_id' => $revision->id]);

        return $batch->fresh();
    }

    public function test_review_uses_only_approved_snapshot_and_does_not_write_records(): void
    {
        $batch = $this->batch();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->get(route('mdb-workflow.data-entry-export.show', $batch))->assertOk()->assertSee('2 observations')->assertSee('service load is not mapped');
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('mdb_workflow_exports', 0);
    }

    public function test_pending_and_unapproved_snapshots_are_rejected(): void
    {
        $batch = $this->batch();
        $batch->update(['survey_status' => 'awaiting_verification']);
        $this->get(route('mdb-workflow.data-entry-export.show', $batch))->assertForbidden();
        $batch->update(['survey_status' => 'approved', 'approved_revision_id' => null]);
        $this->getJson(route('mdb-workflow.data-entry-export.show', $batch))->assertUnprocessable();
    }

    public function test_unauthorized_and_stale_downloads_are_rejected_before_generation(): void
    {
        $batch = $this->batch();
        $this->post(route('mdb-workflow.data-entry-export.download', $batch), ['revision_sha256' => str_repeat('0', 64)])->assertConflict();
        $other = User::factory()->create(['role' => UserRole::Surveyor]);
        $this->actingAs($other)->get(route('mdb-workflow.data-entry-export.show', $batch))->assertForbidden();
        $this->post(route('mdb-workflow.data-entry-export.download', $batch), ['revision_sha256' => str_repeat('0', 64)])->assertForbidden();
    }

    public function test_revision_corruption_and_entry_contributor_approval_are_rejected(): void
    {
        $batch = $this->batch();
        DB::table('mdb_workflow_revisions')->where('id', $batch->approved_revision_id)->update(['sha256' => str_repeat('0', 64)]);
        $this->getJson(route('mdb-workflow.data-entry-export.show', $batch))->assertUnprocessable();
        DB::table('mdb_workflow_revisions')->where('id', $batch->approved_revision_id)->update(['sha256' => app(SnapshotService::class)->hash(DataEntrySnapshot::make())]);
        $batch->update(['entry_actor_ids' => [auth()->id()]]);
        $this->getJson(route('mdb-workflow.data-entry-export.show', $batch))->assertUnprocessable();
    }

    public function test_mapping_preserves_endpoint_values_counts_and_identifiers(): void
    {
        $snapshot = DataEntrySnapshot::make();
        $snapshot['transformers'][0]['entry_rows'] = array_reverse($snapshot['transformers'][0]['entry_rows']);
        $payload = app(DataEntryPayload::class)->build($snapshot);
        [$start, $end] = $payload['rows'];
        $this->assertCount(69, $start);
        $this->assertSame(['S', 'E'], array_column($payload['rows'], 'S_E'));
        $this->assertSame(['Ant', '', 'GNAT', 'Wasp'], [$start['Con_Size_A'], $start['Con_Size_B'], $start['Con_Size_C'], $start['Con_Size_N']]);
        $this->assertSame('R-B', $start['Pole_Phase']);
        $this->assertSame('01080825001', $start['Pole_Number']);
        $this->assertSame(1080825001.0, $start['GPS_NO']);
        $this->assertSame('1', $start['Group']);
        $this->assertSame('HAZECO', $start['DISCO']);
        $this->assertSame('0.4kV', $start['Line_Voltage']);
        $this->assertSame('Secondary Distribution', $start['Pole_Use']);
        $this->assertSame([0, 3], array_column($payload['rows'], 'RS_Con1'));
        $this->assertSame(0, $end['SC_Con']);
        $this->assertSame('PEL', $start['Transformer_Make']);
        $this->assertNull($end['Transformer_Make']);
        $this->assertSame(5, $end['PV_Size']);
        $this->assertSame('000123', $end['AMI_Meter_No']);
        $this->assertNull($end['Sanction Load']);
        $this->assertNull($end['x']);
        $this->assertNull($end['y']);
        $this->assertSame('0.4kV', $end['Line_Voltage']);
    }

    public function test_unrepresentable_values_are_reported_without_duplication_or_rounding(): void
    {
        $snapshot = DataEntrySnapshot::make();
        $snapshot['transformers'][0]['header']['feeder_identifier'] = 'F-001';
        $snapshot['transformers'][0]['header']['location'] = str_repeat('X', 256);
        $snapshot['transformers'][0]['entry_rows'][1]['pv_details'][0]['installed_capacity_kw'] = 5.5;
        $snapshot['transformers'][0]['entry_rows'][0]['pv_details'] = [['reference' => 'one'], ['reference' => 'two']];
        $snapshot['transformers'][0]['pv_records'] = [['reference' => 'unlinked', 'installed_capacity_kw' => 9]];
        $snapshot['transformers'][0]['entry_rows'][0]['intersection'] = true;
        $payload = app(DataEntryPayload::class)->build($snapshot);
        $this->assertCount(2, $payload['rows']);
        $this->assertNull($payload['rows'][1]['PV_Size']);
        $this->assertNull($payload['rows'][0]['PV_Consumer_REF_NO']);
        $this->assertNull($payload['rows'][0]['Transformer_Location']);
        $this->assertNull($payload['rows'][0]['Feeder_Code']);
        $this->assertStringContainsString('multiple PV details', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('without rounding', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('no original S/E observation link', implode(' ', $payload['warnings']));
        $this->assertStringContainsString('intersection flag', implode(' ', $payload['warnings']));
    }

    public function test_incomplete_pairs_are_rejected(): void
    {
        $snapshot = DataEntrySnapshot::make();
        array_pop($snapshot['transformers'][0]['entry_rows']);
        $this->expectException(ValidationException::class);
        app(DataEntryPayload::class)->build($snapshot);
    }

    private function remoteWorker(?callable $alter = null): void
    {
        config(['data_entry_mdb.driver' => 'http', 'data_entry_mdb.http_url' => 'https://mdb-worker.example',
            'data_entry_mdb.secret' => str_repeat('s', 40), 'data_entry_mdb.powershell' => '/not-installed-on-cpanel']);
        Http::preventStrayRequests();
        Http::fake(['https://mdb-worker.example/v1/data-entry-exports' => function ($request) use ($alter) {
            $payload = json_decode($request->body(), true);
            $this->assertSame(hash('sha256', str_replace("\r\n", "\n", file_get_contents(resource_path('mdb/data-entry/schema.json')))), $payload['schema_sha256']);
            [$stamp, $signature] = explode(':', substr($request->header('Authorization')[0], 9));
            $this->assertSame(hash_hmac('sha256', $stamp."\n".hash('sha256', $request->body()), str_repeat('s', 40)), $signature);
            $bytes = file_get_contents(resource_path('mdb/data-entry/empty.mdb'));
            $result = ['validated' => true, 'observations' => count($payload['rows']), 'gps_points' => 0,
                'sha256' => hash('sha256', $bytes), 'payload_sha256' => hash('sha256', $request->body()), 'mdb_base64' => base64_encode($bytes)];
            if ($alter) {
                $result = $alter($result);
            }
            $body = json_encode($result);

            return Http::response($body, 200, ['X-MDB-Signature' => hash_hmac('sha256', $body, str_repeat('s', 40))]);
        }]);
    }

    public function test_http_worker_download_works_without_local_windows_tools(): void
    {
        $this->remoteWorker();
        $batch = $this->batch();
        $before = $batch->getRawOriginal();
        $payload = app(DataEntryMdbExport::class)->prepare($batch);
        $response = $this->post(route('mdb-workflow.data-entry-export.download', $batch), ['revision_sha256' => $payload['revision_sha256']]);
        $response->assertOk()->assertDownload()->assertHeader('X-Data-Entry-Observations', '2');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertSame(hash_file('sha256', resource_path('mdb/data-entry/empty.mdb')), hash_file('sha256', $path));
            $this->assertSame($before, $batch->fresh()->getRawOriginal());
            $this->assertDatabaseCount('mdb_workflow_exports', 0);
            Http::assertSentCount(1);
        } finally {
            unlink($path);
        }
    }

    public function test_http_worker_rejects_wrong_payload_counts_hash_and_non_mdb_outputs(): void
    {
        $payload = app(DataEntryPayload::class)->build(DataEntrySnapshot::make());
        foreach (['payload_sha256' => str_repeat('0', 64), 'observations' => 4, 'gps_points' => 1,
            'sha256' => str_repeat('0', 64), 'mdb_base64' => base64_encode('This is not an MDB file.')] as $key => $value) {
            $this->remoteWorker(fn ($result) => array_replace($result, [$key => $value]));
            $files = glob(storage_path('app/private/data-entry-mdb/*'));
            try {
                app(DataEntryMdbExport::class)->generate($payload);
                $this->fail('Invalid worker result was accepted: '.$key);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data_entry_mdb', $exception->errors());
                $this->assertSame($files, glob(storage_path('app/private/data-entry-mdb/*')));
            }
        }
    }

    public function test_http_worker_requires_https_configuration_and_authenticated_responses(): void
    {
        $batch = $this->batch();
        $sha = app(DataEntryMdbExport::class)->prepare($batch)['revision_sha256'];
        $url = route('mdb-workflow.data-entry-export.download', $batch);
        config(['data_entry_mdb.driver' => 'http', 'data_entry_mdb.http_url' => null, 'data_entry_mdb.secret' => null]);
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson($url, ['revision_sha256' => $sha])->assertUnprocessable()->assertJsonValidationErrors('data_entry_mdb');
        Http::assertNothingSent();
        config(['data_entry_mdb.http_url' => 'http://insecure-worker.example', 'data_entry_mdb.secret' => str_repeat('s', 40)]);
        $this->postJson($url, ['revision_sha256' => $sha])->assertUnprocessable()->assertJsonValidationErrors('data_entry_mdb');
        Http::assertNothingSent();
        config(['data_entry_mdb.http_url' => 'https://mdb-worker.example']);
        Http::fake(['https://mdb-worker.example/*' => Http::response('{}', 200, ['X-MDB-Signature' => 'invalid'])]);
        $this->postJson($url, ['revision_sha256' => $sha])->assertUnprocessable()->assertJsonValidationErrors('data_entry_mdb');
    }

    public function test_unreachable_and_outdated_workers_show_validation_messages(): void
    {
        $this->remoteWorker();
        $batch = $this->batch();
        $sha = app(DataEntryMdbExport::class)->prepare($batch)['revision_sha256'];
        $url = route('mdb-workflow.data-entry-export.download', $batch);
        Http::fake(['https://mdb-worker.example/*' => function () {
            throw new ConnectionException('Connection failed');
        }]);
        $this->postJson($url, ['revision_sha256' => $sha])->assertUnprocessable()->assertJsonValidationErrors('data_entry_mdb');
        $body = json_encode(['error' => 'Unknown endpoint.']);
        Http::fake(['https://mdb-worker.example/*' => Http::response($body, 404,
            ['X-MDB-Signature' => hash_hmac('sha256', $body, str_repeat('s', 40))])]);
        $this->postJson($url, ['revision_sha256' => $sha])->assertUnprocessable()->assertJsonValidationErrors('data_entry_mdb');
    }

    public function test_actual_dao_writer_and_download_match_snapshot_without_database_writes(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('data_entry_mdb.powershell'))) {
            $this->markTestSkipped('Windows Access runtime required.');
        }
        $batch = $this->batch();
        $before = $batch->getRawOriginal();
        $payload = app(DataEntryMdbExport::class)->prepare($batch);
        $response = $this->post(route('mdb-workflow.data-entry-export.download', $batch), ['revision_sha256' => $payload['revision_sha256']]);
        $response->assertOk()->assertDownload('data_entry_pdf_batch-'.$batch->id.'_r1.mdb')->assertHeader('X-Data-Entry-Observations', '2');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));
            $this->assertSame($before, $batch->fresh()->getRawOriginal());
            $this->assertDatabaseCount('mdb_workflow_exports', 0);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
