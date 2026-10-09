<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\Mdb\ProcessSourceFile;
use App\Models\AuditLog;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\ExportJob;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\MdbTeam;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\GpxParser;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\SnapshotService;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SurveyBatch $batch;

    private SurveyTeam $team;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'MDB', 'name' => 'MDB project', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'Grid']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F', 'feeder_name' => 'Feeder', 'total_transformers' => 0, 'status' => 'active']);
        $this->team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'S', 'name' => 'Survey team', 'status' => 'active']);
        $this->batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $this->team->id,
            'survey_date' => '2026-10-09', 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin);
    }

    private function gpx(): string
    {
        return '<g:gpx xmlns:g="http://www.topografix.com/GPX/1/1" version="1.1"><g:wpt lat="30.464656" lon="71.917033"><g:name>0001</g:name><g:ele>123.4</g:ele><g:time>2025-08-23T08:15:32Z</g:time><g:desc>Transformer</g:desc></g:wpt><g:wpt lat="30.464641" lon="71.917066"><g:name>SSS</g:name></g:wpt></g:gpx>';
    }

    private function source(): SourceFile
    {
        $this->post(route('mdb-workflow.sources.store', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'file' => UploadedFile::fake()->createWithContent('survey.gpx', $this->gpx())])->assertSessionHasNoErrors()->assertRedirect();

        return $this->batch->sources()->latest('id')->firstOrFail();
    }

    public function test_names_namespaces_elevation_and_utc_timestamp_are_preserved_and_job_is_idempotent(): void
    {
        $source = $this->source();
        Queue::assertPushed(ProcessSourceFile::class);
        $job = new ProcessSourceFile($source->id);
        $job->handle(app(GpxParser::class), app(SnapshotService::class));
        $job->handle(app(GpxParser::class), app(SnapshotService::class));
        $this->assertDatabaseCount('mdb_workflow_waypoints', 2);
        $point = $source->waypoints()->where('name', '0001')->firstOrFail();
        $this->assertSame('0001', $point->name);
        $this->assertSame('2025-08-23T08:15:32+00:00', $point->recorded_at->toIso8601String());
        $this->assertSame('13:15:32', $point->recorded_at->timezone('Asia/Karachi')->format('H:i:s'));
        $this->assertEquals(123.4, $point->elevation);
        $this->assertSame('Transformer', $point->description);
        $this->assertStringContainsString('<g:name>0001</g:name>', $point->original_entry['xml']);
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame(3, $this->batch->fresh()->revision);
    }

    public function test_uploaded_sources_are_private_hashed_versioned_and_only_batch_members_can_download(): void
    {
        $source = $this->source();
        Storage::disk('local')->assertExists($source->path);
        $this->assertSame(hash('sha256', $this->gpx()), $source->sha256);
        $this->post(route('mdb-workflow.sources.store', $this->batch), ['revision' => 2, 'parent_source_id' => $source->id,
            'file' => UploadedFile::fake()->createWithContent('replacement.gpx', $this->gpx())])->assertSessionHasNoErrors();
        $new = $this->batch->sources()->latest('id')->first();
        $this->assertSame(2, $new->version);
        $this->assertSame($source->id, $new->parent_source_id);
        Storage::disk('local')->assertExists($source->path);
        $creator = User::factory()->create(['role' => UserRole::MdbTeamUser]);
        $this->actingAs($creator)->get(route('mdb-workflow.source', [$this->batch, $source]))->assertForbidden();
        $mdbTeam = MdbTeam::create(['project_id' => $this->batch->project_id, 'code' => 'MDB1', 'name' => 'Creation', 'status' => 'active']);
        $mdbTeam->members()->attach($creator);
        $this->get(route('mdb-workflow.source', [$this->batch, $source]))->assertOk()->assertDownload('survey.gpx');
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 3, 'action' => 'approve'])->assertForbidden();
        $survey = User::factory()->create(['role' => UserRole::SurveyTeamLeader]);
        $this->team->members()->attach($survey);
        $this->actingAs($survey)->post(route('mdb-workflow.transformers.store', $this->batch), ['revision' => 3, 'code' => 'X'])->assertForbidden();
    }

    public function test_malformed_and_disguised_sources_are_rejected_before_private_storage(): void
    {
        foreach ([['evil.gpx', '<!DOCTYPE gpx [<!ENTITY e SYSTEM "file:///secret">]><gpx/>'], ['fake.pdf', 'not a PDF']] as [$name, $body]) {
            $this->post(route('mdb-workflow.sources.store', $this->batch), ['revision' => 1, 'file' => UploadedFile::fake()->createWithContent($name, $body)])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('mdb_workflow_sources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_parser_reports_duplicate_and_conflicting_coordinates_without_discarding_provenance(): void
    {
        $xml = '<gpx xmlns="http://www.topografix.com/GPX/1/0"><wpt lat="30" lon="72"><name>01</name></wpt><wpt lat="30" lon="72"><name>01</name></wpt><wpt lat="31" lon="72"><name>01</name></wpt></gpx>';
        $result = app(GpxParser::class)->parse($xml);
        $this->assertCount(3, $result['waypoints']);
        $this->assertSame(['duplicate_waypoint', 'conflicting_coordinates'], array_column($result['warnings'], 'code'));
    }

    public function test_stale_edits_do_not_create_sections_or_invalidate_a_current_revision(): void
    {
        $this->batch->update(['revision' => 7]);
        $this->post(route('mdb-workflow.transformers.store', $this->batch), ['revision' => 6, 'code' => 'T-STALE'])->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('mdb_workflow_transformers', 0);
        $this->assertSame(7, $this->batch->fresh()->revision);
    }

    public function test_approval_is_revision_bound_and_editing_data_supersedes_exports(): void
    {
        $this->mock(NetworkValidator::class, fn ($mock) => $mock->shouldReceive('validate')->andReturn(['valid' => true, 'errors' => [], 'warnings' => [], 'topology' => []]));
        $transformer = NetworkTransformer::create(['batch_id' => $this->batch->id, 'code' => 'T-01', 'capacity_kva' => 100]);
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 1, 'action' => 'review'])->assertSessionHasNoErrors();
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 1, 'action' => 'approve'])->assertSessionHasNoErrors();
        $approved = $this->batch->fresh()->approvedRevision;
        $this->assertNotNull($approved);
        $this->assertSame(1, $approved->number);
        $template = Template::create(['code' => 'fixture', 'version' => '1', 'path' => 'unused', 'sha256' => str_repeat('a', 64), 'synergee_version' => '5']);
        $export = ExportJob::create(['batch_id' => $this->batch->id, 'revision_id' => $approved->id, 'template_id' => $template->id,
            'idempotency_key' => str_repeat('e', 64), 'status' => 'generated', 'mapping_version' => '1', 'requested_by' => $this->admin->id]);
        $this->put(route('mdb-workflow.transformers.update', [$this->batch, $transformer]), ['revision' => 1, 'code' => 'T-01', 'capacity_kva' => 125,
            'header' => ['manually_verified' => '1']])->assertSessionHasNoErrors();
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertSame(2, $this->batch->fresh()->revision);
        $this->assertNotNull($export->fresh()->superseded_at);
        $this->assertEquals(100, $approved->fresh()->snapshot['transformers'][0]['capacity_kva']);
        $this->get(route('mdb-workflow.exports.download', $export))->assertStatus(409);
    }

    public function test_failed_critical_validation_prevents_approval_and_rejection_requires_comment(): void
    {
        $this->batch->update(['status' => 'awaiting_verification']);
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 1, 'action' => 'approve'])->assertSessionHasErrors('validation');
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 1, 'action' => 'reject'])->assertSessionHasErrors('comment');
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => 1, 'action' => 'reject', 'comment' => 'Missing coordinates 878 and 879'])->assertSessionHasNoErrors();
        $this->assertSame('returned', $this->batch->fresh()->status);
        $this->assertDatabaseHas('mdb_workflow_decisions', ['decision' => 'reject', 'comments' => 'Missing coordinates 878 and 879']);
    }

    public function test_coordinate_corrections_preserve_original_and_require_verifier_permission(): void
    {
        $source = $this->source();
        (new ProcessSourceFile($source->id))->handle(app(GpxParser::class), app(SnapshotService::class));
        $point = $source->waypoints()->first();
        $original = $point->latitude;
        $this->post(route('mdb-workflow.waypoints.corrections.store', [$this->batch, $point]), ['revision' => 3, 'latitude' => 30.5,
            'longitude' => 71.9, 'reason' => 'Measured replacement GPS coordinate', 'evidence' => 'Inspection log 101'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($original, $point->fresh()->latitude);
        $this->assertEquals(30.5, $point->fresh()->correction['latitude']);
        $this->assertSame($this->admin->id, $point->fresh()->correction_approved_by);
    }

    public function test_dashboard_simple_entry_and_advanced_records_render_with_project_configuration(): void
    {
        $this->source();
        $transformer = NetworkTransformer::create(['batch_id' => $this->batch->id, 'code' => 'T-4221215879', 'capacity_kva' => 100]);
        Storage::disk('local')->put('fixture.pdf', '%PDF-1.4 fixture');
        $pdf = SourceFile::create(['batch_id' => $this->batch->id, 'kind' => 'pdf', 'path' => 'fixture.pdf', 'original_name' => 'fixture.pdf', 'sha256' => hash('sha256', '%PDF-1.4 fixture'),
            'bytes' => 16, 'mime' => 'application/pdf', 'uploaded_by' => $this->admin->id, 'status' => 'ready', 'metadata' => ['page_count' => 27]]);
        $section = $transformer->sections()->create(['start_reference' => '878', 'end_reference' => '879', 'source_pdf_id' => $pdf->id, 'source_page' => 1, 'source_row' => '1-2',
            'phases' => ['R', 'N'], 'conductors' => ['R' => 'ANT', 'N' => 'ANT'], 'original_entry' => ['manually_verified' => true], 'measured_length_m' => 5, 'measured_length_reason' => 'Field log']);
        $section->consumers()->create(['category' => 'rs', 'count' => 1, 'original_value' => '1', 'demand' => ['method' => 'measured', 'evidence' => 'log', 'phase_values' => ['R' => ['customers' => 1, 'kw' => 1, 'kvar' => 0, 'kva' => 1]]]]);
        $transformer->pvRecords()->create(['section_id' => $section->id, 'reference' => 'PV-01', 'installed_capacity_kw' => 1, 'remarks' => 'Verified source fixture']);
        $export = $this->approvedExport();
        $export->validations()->create(['recorded_by' => $this->admin->id, 'status' => 'downloaded']);
        $this->get(route('mdb-workflow.index'))->assertOk()->assertSee('MDB creation')->assertSee('MDB project');
        $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertSee('T-4221215879')->assertSee('Save &amp; Add Next', false)->assertSee('Continue Current Transformer')->assertSee('878')->assertSee('879');
        $this->get(route('mdb-workflow.advanced', $this->batch))->assertOk()->assertSee('MDB export worker not configured')->assertSee('PV-01')->assertSee('878')->assertSee('879');
        $this->get(route('mdb-workflow.create'))->assertOk();
        $this->get(route('mdb-workflow.config', $this->batch->project))->assertOk();
    }

    public function test_configuration_updates_invalidate_frozen_batch_and_advance_revision(): void
    {
        $config = Configuration::create(['project_id' => $this->batch->project_id, 'epsg' => 32643, 'settings' => ['frequency_hz' => 50]]);
        $revision = app(SnapshotService::class)->capture($this->batch, $this->admin->id);
        $this->batch->update(['approved_revision_id' => $revision->id, 'status' => 'approved']);
        $this->put(route('mdb-workflow.config.update', $this->batch->project), ['revision' => 1, 'epsg' => 32642,
            'settings_json' => '{"frequency_hz":50}', 'load_assumptions_json' => '{"method":"measured"}', 'approved' => '1', 'reason' => 'Engineer reviewed coordinate zone'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertSame(3, $this->batch->fresh()->revision);
        $this->assertSame(32643, $revision->fresh()->snapshot['configuration']['epsg']);
    }

    public function test_frozen_revisions_cannot_be_overwritten(): void
    {
        $revision = app(SnapshotService::class)->capture($this->batch, $this->admin->id);
        $this->expectException(\LogicException::class);
        $revision->update(['sha256' => str_repeat('f', 64)]);
    }

    public function test_custom_analysis_role_survives_profile_updates_and_primary_role_changes(): void
    {
        $user = User::factory()->create(['role' => UserRole::ManagementViewer]);
        $user->assignRole('analysis_team');
        $user->update(['name' => 'Analyst profile changed']);
        $this->assertTrue($user->fresh()->hasRole('analysis_team'));
        $user->update(['role' => UserRole::MdbTeamUser]);
        $this->assertTrue($user->fresh()->hasRole('analysis_team'));
        $this->assertTrue($user->fresh()->hasRole('mdb_team_user'));
        $this->assertFalse($user->fresh()->hasRole('management_viewer'));
    }

    public function test_failed_source_retry_preserves_upload_provenance_and_advances_revision(): void
    {
        $source = $this->source();
        $source->update(['metadata' => $source->metadata + ['drive_file_id' => 'authorized-file-123', 'drive_version' => '7']]);
        $job = new ProcessSourceFile($source->id);
        $job->failed(new \RuntimeException('Unreadable PDF'));
        $this->assertSame('authorized-file-123', $source->fresh()->metadata['drive_file_id']);
        $this->assertSame('7', $source->fresh()->metadata['drive_version']);
        $this->assertSame('failed', $source->fresh()->status);
        $this->assertSame(3, $this->batch->fresh()->revision);
        $this->post(route('mdb-workflow.sources.retry', [$this->batch, $source]), ['revision' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4, $this->batch->fresh()->revision);
        $this->assertSame('queued', $source->fresh()->status);
        $job->handle(app(GpxParser::class), app(SnapshotService::class));
        $this->assertDatabaseCount('mdb_workflow_waypoints', 2);
        $this->assertSame('7', $source->fresh()->metadata['drive_version']);
        $this->assertArrayNotHasKey('error', $source->fresh()->metadata);
    }

    private function approvedExport(): ExportJob
    {
        $template = Template::create(['code' => 'analysis-fixture', 'version' => '1', 'path' => 'unused', 'sha256' => str_repeat('a', 64), 'synergee_version' => '5']);
        $revision = app(SnapshotService::class)->capture($this->batch, $this->admin->id);
        $this->batch->update(['approved_revision_id' => $revision->id, 'status' => 'approved']);

        return ExportJob::create(['batch_id' => $this->batch->id, 'revision_id' => $revision->id, 'template_id' => $template->id,
            'idempotency_key' => hash('sha256', 'analysis-'.$this->batch->id), 'status' => 'generated', 'mapping_version' => '1', 'requested_by' => $this->admin->id]);
    }

    public function test_analysis_acceptance_requires_all_checks_and_return_revokes_current_output(): void
    {
        $export = $this->approvedExport();
        $url = route('mdb-workflow.analysis.store', $export);
        $this->post($url, ['status' => 'accepted', 'synergee_version' => '5'])->assertRedirect()->assertSessionHasErrors('status');
        foreach (['downloaded', 'opened', 'connectivity_checked', 'load_flow_checked', 'accepted'] as $step) {
            $this->post($url, ['status' => $step, 'synergee_version' => '5', 'comments' => 'Engineering fixture check recorded'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseHas('mdb_workflow_model_validations', ['status' => 'accepted', 'export_job_id' => $export->id]);
        $this->post($url, ['status' => 'returned', 'synergee_version' => '5', 'comments' => 'Source connection requires correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertSame('returned', $this->batch->fresh()->status);
        $this->assertNotNull($export->fresh()->superseded_at);
        $this->post($url, ['status' => 'accepted', 'synergee_version' => '5'])->assertStatus(409);
    }

    public function test_section_and_pv_corrections_preserve_raw_values_and_page_associations(): void
    {
        $transformer = $this->batch->transformers()->create(['code' => 'CORRECTION', 'header' => ['pages' => [['source_pdf_id' => 1, 'page' => 1, 'role' => 'header', 'confirmed' => true]]]]);
        $section = $transformer->sections()->create(['start_reference' => '0001', 'end_reference' => 'SSS']);
        $consumer = $section->consumers()->create(['category' => 'rs', 'count' => 1, 'original_value' => 'I']);
        $pv = $transformer->pvRecords()->create(['section_id' => $section->id, 'reference' => 'PV', 'installed_capacity_kw' => 1, 'original_entry' => ['installed_capacity' => 'handwritten I']]);
        $this->put(route('mdb-workflow.pv.update', [$this->batch, $transformer, $pv]), ['revision' => 1, 'reference' => 'PV', 'installed_capacity_kw' => 2,
            'original_entry' => ['installed_capacity' => 'handwritten I', 'manually_verified' => '1']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(2, $pv->fresh()->installed_capacity_kw);
        $this->assertSame('handwritten I', $pv->fresh()->original_entry['installed_capacity']);
        $this->put(route('mdb-workflow.transformers.update', [$this->batch, $transformer]), ['revision' => 2, 'code' => 'CORRECTION', 'header' => ['make' => 'Reviewed']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $transformer->fresh()->header['pages'][0]['page']);
        $this->delete(route('mdb-workflow.sections.destroy', [$this->batch, $transformer, $section]), ['revision' => 3, 'reason' => 'Duplicate transcription row'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('mdb_workflow_sections', ['id' => $section->id]);
        $this->assertNull($pv->fresh()->section_id);
        $oldAudit = AuditLog::where('action', 'mdb.data_edited')->latest('id')->first();
        $this->assertSame('I', $oldAudit->old_values['transformers'][0]['sections'][0]['consumers'][0]['original_value']);
    }

    public function test_impossible_calendar_timestamps_are_rejected_without_normalization(): void
    {
        $xml = str_replace('2025-08-23', '2025-02-31', $this->gpx());
        $this->expectException(ValidationException::class);
        app(GpxParser::class)->parse($xml);
    }
}
