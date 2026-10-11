<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\EntryLookups;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\Exporter;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\WindowsExporter;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MdbOperatorEntryTest extends TestCase
{
    use RefreshDatabase;

    private SurveyBatch $batch;

    private NetworkTransformer $transformer;

    private SourceFile $pdf;

    private SourceFile $gpx;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($this->admin);
        $project = Project::create(['code' => 'ENTRY', 'name' => 'Entry fixture', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'GS', 'name' => 'Substation']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id,
            'grid_station_id' => $grid->id, 'feeder_code' => '000407', 'feeder_name' => 'Feeder fixture', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Team', 'status' => 'active']);
        $this->batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'survey_date' => '2026-10-08', 'created_by' => $this->admin->id]);
        foreach (['pdf', 'gpx'] as $kind) {
            $bytes = $kind === 'pdf' ? '%PDF-1.4 fixture' : '<gpx />';
            Storage::disk('local')->put('entry.'.$kind, $bytes);
            $this->$kind = SourceFile::create(['batch_id' => $this->batch->id, 'kind' => $kind, 'path' => 'entry.'.$kind, 'original_name' => $kind === 'pdf' ? 'A081026.pdf' : 'entry.gpx',
                'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'mime' => $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml',
                'uploaded_by' => $this->admin->id, 'status' => 'ready', 'metadata' => $kind === 'pdf' ? ['page_count' => 14] : []]);
        }
        foreach (['001', '010', '002'] as $index => $name) {
            $this->gpx->waypoints()->create(['batch_id' => $this->batch->id, 'name' => $name, 'latitude' => 30.001 + $index / 10000, 'longitude' => 71.001]);
        }
        $this->transformer = $this->batch->transformers()->create(['code' => 'T-entry', 'capacity_kva' => 100,
            'source_waypoint_id' => $this->gpx->waypoints()->first()->id, 'header' => ['substation_name' => 'Substation', 'substation_identifier' => 'GS', 'feeder_name' => 'Feeder fixture',
                'feeder_identifier' => '000407', 'division' => 'Division', 'subdivision' => 'Subdivision', 'subdivision_code' => 'SD', 'service_category' => 'General Duty',
                'make' => 'Fixture', 'location' => 'Fixture', 'mounting' => 'Double Pole', 'survey_date' => '2026-10-08', 'team_group' => '01', 'inspector' => 'Fixture',
                'pages' => [['source_pdf_id' => $this->pdf->id, 'page' => 1, 'role' => 'header', 'confirmed' => true]]]]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['revision' => $this->batch->fresh()->revision, 'client_uuid' => (string) Str::uuid(), 'save_uuid' => (string) Str::uuid(),
            'pair_number' => 1, 'designation' => 'S', 'group_number' => '01', 'row_date' => '2026-10-08', 'waypoint_reference' => '001', 'gpx_source_id' => $this->gpx->id,
            'source_pdf_id' => $this->pdf->id, 'source_page' => 1, 'source_row' => '1', 'conductors' => ['R' => 'A', 'Y' => '__none__', 'B' => '__none__', 'N' => 'A'],
            'equipment_type' => 'SP', 'pole_class' => 'S', 'pole_height' => 31, 'pole_height_unit' => 'ft', 'consumers' => array_fill_keys(array_keys(EntryLookups::CONSUMERS), null),
            'intersection' => false, 'pv_details' => [], 'inheritance' => []], $changes);
    }

    private function save(array $changes = []): EntryRow
    {
        $payload = $this->payload($changes);
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $payload)->assertOk();

        return $this->transformer->entryRows()->where('client_uuid', $payload['client_uuid'])->firstOrFail();
    }

    private function entrySettings(): void
    {
        Configuration::create(['project_id' => $this->batch->project_id, 'settings' => ['entry' => ['two_digit_year_start' => 2000, 'pole_height_unit' => 'ft']]]);
    }

    private function headerPayload(array $changes = []): array
    {
        return array_replace(['revision' => $this->batch->fresh()->revision, 'code' => 'T-new-header', 'capacity_kva' => 100,
            'source_waypoint_id' => null, 'source_pdf_id' => $this->pdf->id, 'source_page' => 1,
            'header' => ['substation_name' => 'Keep this substation', 'location' => 'Keep this location', 'survey_date' => '2026-10-08',
                'client_transformer_information' => 'Client transformer information']], $changes);
    }

    public function test_header_returns_all_specific_validation_errors_and_logs_reference_without_saving(): void
    {
        Log::spy();
        $this->batch->update(['staged_workflow' => true]);
        $revision = $this->batch->fresh()->revision;
        $response = $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload([
            'code' => $this->transformer->code, 'capacity_kva' => -100,
            'header' => ['survey_date' => 'not a date', 'source_survey_identifier' => '123'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['code', 'capacity_kva', 'header.survey_date', 'header.source_survey_identifier']);
        $this->assertStringContainsString('already exists', $response->json('errors.code.0'));
        $this->assertStringContainsString('11-digit', $response->json('errors')['header.source_survey_identifier'][0]);
        $this->assertTrue(Str::isUuid($response->json('reference')));
        $this->assertSame($revision, $this->batch->fresh()->revision);
        $this->assertSame(1, $this->batch->transformers()->count());
        Log::shouldHaveReceived('warning')->once()->with('Survey header save rejected', \Mockery::on(fn ($context) => $context['reference'] === $response->json('reference') && $context['http_status'] === 422 && isset($context['exception'])));
    }

    public function test_first_staged_header_has_a_specific_required_client_information_error(): void
    {
        $batch = $this->batch->replicate();
        $batch->staged_workflow = true;
        $batch->save();
        foreach ([$this->pdf, $this->gpx] as $source) {
            $copy = $source->replicate();
            $copy->batch_id = $batch->id;
            $copy->save();
        }
        $this->postJson(route('mdb-workflow.entry.headers.store', $batch), $this->headerPayload([
            'source_pdf_id' => null, 'header' => ['client_transformer_information' => '', 'location' => 'Retain this location'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('header.client_transformer_information')
            ->assertJsonFragment(['header.client_transformer_information' => ['Enter the client-provided transformer information for the first transformer.']]);
        $this->assertSame(0, $batch->transformers()->count());
    }

    public function test_header_source_readiness_failure_explains_why_it_was_rejected(): void
    {
        $this->batch->update(['staged_workflow' => true]);
        $this->pdf->update(['status' => 'queued']);
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload())
            ->assertUnprocessable()->assertJsonPath('message', 'Both PDF and GPX must finish processing before entry.');
        $this->assertSame(1, $this->batch->transformers()->count());
    }

    public function test_header_technical_failure_is_logged_safely_and_transaction_rolls_back(): void
    {
        Log::spy();
        $this->partialMock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('invalidate')->once()->andThrow(new \RuntimeException('Private database connection detail'));
        });
        $revision = $this->batch->fresh()->revision;
        $response = $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload())->assertStatus(500);
        $this->assertTrue(Str::isUuid($response->json('reference')));
        $this->assertStringNotContainsString('Private database connection detail', $response->getContent());
        $this->assertSame($revision, $this->batch->fresh()->revision);
        $this->assertSame(1, $this->batch->transformers()->count());
        Log::shouldHaveReceived('error')->once()->with('Survey header save failed', \Mockery::on(fn ($context) => $context['reference'] === $response->json('reference') && $context['exception']->getMessage() === 'Private database connection detail'));
    }

    public function test_header_valid_retry_succeeds_and_invalid_source_fields_are_identified(): void
    {
        $url = route('mdb-workflow.entry.headers.store', $this->batch);
        $this->postJson($url, $this->headerPayload(['source_page' => 100]))->assertUnprocessable()->assertJsonValidationErrors('source_page');
        $this->postJson($url, $this->headerPayload(['source_waypoint_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('source_waypoint_id');
        $this->postJson($url, $this->headerPayload(['revision' => -1]))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $response = $this->postJson($url, $this->headerPayload())->assertOk();
        $this->assertNotNull($response->json('active_transformer_id'));
        $saved = $this->batch->transformers()->findOrFail($response->json('active_transformer_id'));
        $this->assertSame('Keep this substation', $saved->header['substation_name']);
        $this->assertSame('Keep this location', $saved->header['location']);
    }

    public function test_dedicated_survey_roles_have_separate_server_enforced_rights_with_team_scope(): void
    {
        $this->batch->update(['staged_workflow' => true]);
        $operator = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $verifier = User::factory()->create(['role' => UserRole::SurveyDataVerifier]);
        $this->batch->surveyTeam->members()->attach([$operator->id, $verifier->id]);
        $url = route('mdb-workflow.entry.headers.store', $this->batch);
        $this->actingAs($operator)->postJson($url, $this->headerPayload())->assertOk();
        $this->actingAs($verifier)->postJson($url, $this->headerPayload(['code' => 'Verifier cannot enter']))->assertForbidden();
        $this->batch->update(['survey_status' => 'awaiting_verification']);
        $decision = route('mdb-workflow.survey.decision', $this->batch);
        $this->actingAs($operator)->postJson($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertForbidden();
        $this->actingAs($verifier)->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'return', 'remarks' => 'Correct the PDF date'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('returned', $this->batch->fresh()->survey_status);
        $this->assertSame($verifier->id, $this->batch->fresh()->survey_verifier_id);
        $outsider = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $this->actingAs($outsider)->getJson(route('mdb-workflow.entry.data', $this->batch))->assertForbidden();
    }

    private function workflowActors(): array
    {
        $actors = [User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]),
            User::factory()->create(['role' => UserRole::SurveyDataVerifier]),
            User::factory()->create(['role' => UserRole::MdbGenerator])];
        $this->batch->surveyTeam->members()->attach(array_map(fn ($user) => $user->id, $actors));
        $this->batch->update(['staged_workflow' => true]);

        return $actors;
    }

    private function completeSurvey(User $operator): void
    {
        $this->actingAs($operator);
        $this->save();
        $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function kmzUpload(string $content = '<Document/>'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'survey-kmz-');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString('doc.kml', '<kml xmlns="http://www.opengis.net/kml/2.2">'.$content.'</kml>');
        $archive->close();
        try {
            return UploadedFile::fake()->createWithContent('survey.kmz', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_three_role_submission_return_and_approval_freeze_data_and_audit_every_actor(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $decision = route('mdb-workflow.survey.decision', $this->batch);
        $this->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('awaiting_verification', $this->batch->fresh()->status);
        $this->assertSame($operator->id, $this->batch->fresh()->entry_operator_id);
        $this->assertNotNull($this->batch->fresh()->entry_completed_at);
        $row = $this->transformer->entryRows()->first();
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload())->assertForbidden();
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]), $this->payload())->assertForbidden();
        $this->deleteJson(route('mdb-workflow.entry.rows.destroy', [$this->batch, $this->transformer, $row]), ['revision' => $this->batch->fresh()->revision])->assertForbidden();
        $this->postJson(route('mdb-workflow.transition', $this->batch), ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertForbidden();
        $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertDontSee('id="entry-header-form"', false)
            ->assertDontSee('id="entry-row-form"', false)->assertDontSee('id="entry-pdf-mark"', false);
        $this->actingAs($creator)->postJson($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertForbidden();
        $this->actingAs($verifier)->postJson($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'return'])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'return', 'remarks' => 'Check the page and GPS reference'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('returned', $this->batch->fresh()->survey_status);
        $this->assertSame(2, $this->transformer->entryRows()->count());
        $this->actingAs($operator)->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($verifier)->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $batch = $this->batch->fresh();
        $this->assertSame('approved', $batch->status);
        $this->assertSame('approved', $batch->survey_status);
        $this->assertSame($verifier->id, $batch->survey_verifier_id);
        $this->assertNotNull($batch->survey_verified_at);
        $this->assertSame($batch->revision, $batch->approvedRevision->number);
        $this->assertSame($verifier->id, $batch->approvedRevision->created_by);
        $this->assertSame($batch->approvedRevision->sha256, app(SnapshotService::class)->hash(app(SnapshotService::class)->snapshot($batch)));
        $this->actingAs($operator)->postJson(route('mdb-workflow.entry.headers.store', $batch), $this->headerPayload())->assertForbidden();
        $this->actingAs($creator)->postJson(route('mdb-workflow.entry.headers.store', $batch), $this->headerPayload())->assertForbidden();
        foreach (['submit' => $operator, 'return' => $verifier, 'approve' => $verifier] as $action => $actor) {
            $event = AuditLog::where('action', 'mdb.survey_'.$action)->where('auditable_id', $batch->id)->latest('id')->firstOrFail();
            $this->assertSame($actor->id, $event->user_id);
            $this->assertNotNull($event->created_at);
        }
    }

    public function test_role_specific_pages_hide_other_actions_and_readonly_pdf_viewer_keeps_navigation(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $operatorPage = $this->actingAs($operator)->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertSee('Submit for Verification')
            ->assertDontSee('Review all rows &amp; generate MDB', false)->assertDontSee('Review &amp; generate MDB', false)->getContent();
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            file_put_contents(base_path('tmp/mdb-entry-qa/operator-workflow.html'), $operatorPage);
        }
        $this->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertDontSee('Generate MDB')->assertDontSee('value="approve"', false);
        $this->batch->update(['survey_status' => 'awaiting_verification']);
        $this->actingAs($verifier)->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('Return for Correction')
            ->assertSee('value="approve"', false)->assertDontSee('Generate MDB')->assertDontSee('entry.review', false);
        $readonly = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertDontSee('id="entry-header-form"', false)
            ->assertDontSee('id="entry-row-form"', false)->assertDontSee('id="entry-pdf-mark"', false)->getContent();
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            file_put_contents(base_path('tmp/mdb-entry-qa/readonly.html'), $readonly);
        }
        $this->actingAs($creator)->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('Generate MDB')
            ->assertDontSee('value="approve"', false)->assertDontSee('id="entry-header-form"', false);
        $this->get(route('mdb-workflow.advanced', $this->batch))->assertOk()->assertDontSee('name="action" value="review"', false)
            ->assertDontSee('name="action" value="approve"', false)->assertDontSee('Add transformer network');
    }

    public function test_supplemental_permissions_cannot_expand_creator_rights_and_designations_do_not_assign_roles(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $creator->givePermissionTo(['MdbWorkflow:Edit', 'MdbWorkflow:SurveyVerify', 'MdbWorkflow:Verify', 'MdbWorkflow:Process']);
        $this->actingAs($creator)->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload())->assertForbidden();
        $this->batch->update(['survey_status' => 'awaiting_verification']);
        $this->postJson(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $this->batch->revision, 'action' => 'approve'])->assertForbidden();
        $external = User::factory()->create(['role' => UserRole::MdbProcessingUser]);
        $manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $this->batch->surveyTeam->members()->attach($external->id);
        $this->assertFalse($external->hasRole('survey_data_verifier'));
        $this->assertFalse($manager->hasRole('mdb_generator'));
        $this->actingAs($external)->postJson(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $this->batch->revision, 'action' => 'approve'])->assertForbidden();
        $this->postJson(route('mdb-workflow.exports.store', $this->batch), ['revision' => $this->batch->revision])->assertForbidden();
        $generatorRole = Role::findByName('mdb_generator');
        $generatorRole->givePermissionTo('MdbWorkflow:Verify');
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $this->assertFalse($generatorRole->fresh()->hasPermissionTo('MdbWorkflow:Verify'));
    }

    public function test_creator_freezes_pdf_gpx_kmz_and_required_base_files_without_editing_approved_survey(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        Storage::disk('local')->put('base.mdb', file_get_contents(resource_path('mdb/synergee-empty.mdb')));
        Template::create(['code' => 'BASE', 'version' => '1', 'disk' => 'local', 'path' => 'base.mdb',
            'sha256' => hash_file('sha256', resource_path('mdb/synergee-empty.mdb')), 'synergee_version' => 'Fixture',
            'metadata' => ['required_base_files' => ['reviewed.csv']], 'approved_by' => $this->admin->id, 'approved_at' => now('UTC'), 'active' => true]);
        $this->completeSurvey($operator);
        $decision = route('mdb-workflow.survey.decision', $this->batch);
        $this->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $inputs = route('mdb-workflow.generation.inputs.store', $this->batch);
        $this->actingAs($creator)->post($inputs, ['kind' => 'kmz', 'file' => $this->kmzUpload()])->assertForbidden();
        $this->actingAs($verifier)->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($inputs, ['kind' => 'kmz', 'file' => $this->kmzUpload()])->assertForbidden();
        $this->postJson(route('mdb-workflow.exports.store', $this->batch), ['revision' => $this->batch->fresh()->revision])->assertForbidden();
        $this->actingAs($creator);
        $exports = route('mdb-workflow.exports.store', $this->batch);
        $this->postJson($exports, ['revision' => $this->batch->fresh()->revision])->assertUnprocessable()->assertJsonValidationErrors('generation_inputs');
        $this->post($inputs, ['kind' => 'kmz', 'file' => UploadedFile::fake()->createWithContent('bad.kmz', 'not a map')])->assertSessionHasErrors('file');
        $this->post($inputs, ['kind' => 'kmz', 'file' => $this->kmzUpload()])->assertRedirect()->assertSessionHasNoErrors();
        $approved = $this->batch->fresh()->approved_revision_id;
        $approvedHash = $this->batch->fresh()->approvedRevision->sha256;
        $this->postJson($exports, ['revision' => $this->batch->fresh()->revision])->assertUnprocessable()->assertJsonValidationErrors('generation_inputs');
        $this->post($inputs, ['kind' => 'base', 'file' => UploadedFile::fake()->createWithContent('reviewed.csv', 'id,value')])->assertRedirect()->assertSessionHasNoErrors();
        $this->mock(ExportPayloadBuilder::class)->shouldReceive('build')->andReturn(['settings' => [], 'tables' => []]);
        $this->post($exports, ['revision' => $this->batch->fresh()->revision])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($exports, ['revision' => $this->batch->fresh()->revision])->assertRedirect()->assertSessionHasNoErrors();
        $job = $this->batch->exports()->sole();
        $this->assertSame($creator->id, $job->requested_by);
        $manifest = $job->settings['generation_inputs'];
        $this->assertSame($approved, $manifest['approved_revision_id']);
        $this->assertCount(2, $manifest['survey_sources']);
        $this->assertCount(2, $manifest['supporting_files']);
        $payload = json_decode(Storage::disk('local')->get($job->payload_path), true);
        $this->assertSame($manifest, $payload['generation_inputs']);
        $this->assertSame($approved, $this->batch->fresh()->approved_revision_id);
        $this->assertSame($approvedHash, app(SnapshotService::class)->hash(app(SnapshotService::class)->snapshot($this->batch->fresh())));
        $event = AuditLog::where('action', 'mdb_workflow.export_requested')->where('auditable_id', $job->id)->sole();
        $this->assertSame($creator->id, $event->user_id);
        $this->assertNotNull($event->created_at);
        Storage::disk('local')->put('generated.mdb', Storage::disk('local')->get('base.mdb'));
        $job->update(['status' => 'generated', 'output_path' => 'generated.mdb', 'output_sha256' => hash('sha256', Storage::disk('local')->get('generated.mdb'))]);
        $download = route('mdb-workflow.exports.download', $job);
        $this->actingAs($operator)->get($download)->assertForbidden();
        $this->actingAs($verifier)->get($download)->assertForbidden();
        $this->actingAs($creator)->get($download)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.output_downloaded', 'user_id' => $creator->id]);
        Storage::disk('local')->put($manifest['supporting_files'][0]['path'], 'changed bytes');
        $this->getJson($download)->assertUnprocessable()->assertJsonValidationErrors('generation_inputs');
    }

    private function pdfLayout(int $page = 1, ?SourceFile $source = null): array
    {
        $source ??= $this->pdf;

        return $this->putJson(route('mdb-workflow.entry.pdf.layout', [$this->batch, $source]), [
            'revision' => $this->batch->fresh()->revision, 'page' => $page, 'row_count' => 4,
            'bounds' => ['x' => .05, 'y' => .2, 'width' => .9, 'height' => .6],
        ])->assertOk()->json('pdf_rows');
    }

    public function test_pdf_progress_is_saved_with_record_and_undo_retains_link_and_original_pdf(): void
    {
        $bytes = Storage::disk('local')->get($this->pdf->path);
        $marker = $this->pdfLayout()[0];
        $row = $this->save(['pdf_progress_row_id' => $marker['id']]);
        $this->assertSame('PDF row 1', $row->source_row);
        $this->assertTrue(PdfRow::find($marker['id'])->entered);
        $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()
            ->assertJsonPath('pdf_rows.0.entry_row_id', $row->id)->assertJsonPath('pdf_rows.0.entered', true);
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$this->batch, $marker['id']]), [
            'revision' => $this->batch->fresh()->revision, 'entered' => false,
        ])->assertOk()->assertJsonPath('pdf_rows.0.entered', false)->assertJsonPath('pdf_rows.0.entry_row_id', $row->id);
        $this->assertNotNull($row->fresh());
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
            $this->payload(['pair_number' => 2, 'pdf_progress_row_id' => $marker['id']]))->assertUnprocessable();
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$this->batch, $marker['id']]), [
            'revision' => $this->batch->fresh()->revision, 'entered' => true,
        ])->assertOk()->assertJsonPath('pdf_rows.0.entered', true);
        $this->assertSame($bytes, Storage::disk('local')->get($this->pdf->path));
        $this->assertSame($this->pdf->sha256, hash('sha256', $bytes));
    }

    public function test_pdf_row_marking_requires_a_saved_record_and_failed_saves_leave_it_pending(): void
    {
        $marker = $this->pdfLayout()[0];
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$this->batch, $marker['id']]), [
            'revision' => $this->batch->fresh()->revision, 'entered' => true,
        ])->assertUnprocessable();
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
            $this->payload(['revision' => -1, 'pdf_progress_row_id' => $marker['id']]))->assertUnprocessable();
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload())->assertUnprocessable();
        $this->assertFalse(PdfRow::find($marker['id'])->entered);
        $this->assertNull(PdfRow::find($marker['id'])->entry_row_id);
        $this->assertSame(0, $this->transformer->entryRows()->count());
    }

    public function test_pdf_rows_are_scoped_by_pdf_page_and_row_and_cannot_be_relocated_after_linking(): void
    {
        $this->pdfLayout();
        $this->pdfLayout(2);
        $other = $this->pdf->replicate();
        $other->original_name = 'other.pdf';
        $other->path = 'other.pdf';
        $other->save();
        $markers = $this->pdfLayout(1, $other);
        $marker = collect($markers)->first(fn ($row) => $row['source_file_id'] === $this->pdf->id && $row['page'] === 1);
        $this->assertCount(12, $markers);
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
            $this->payload(['source_page' => 2, 'pdf_progress_row_id' => $marker['id']]))->assertUnprocessable();
        $row = $this->save(['pdf_progress_row_id' => $marker['id']]);
        $this->putJson(route('mdb-workflow.entry.pdf.layout', [$this->batch, $this->pdf]), [
            'revision' => $this->batch->fresh()->revision, 'page' => 1, 'row_count' => 3,
            'bounds' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ])->assertUnprocessable();
        $this->pdfLayout(); // Adjusting bounds with the same count preserves IDs and saved links.
        $this->assertSame($row->id, PdfRow::find($marker['id'])->entry_row_id);
        $this->assertTrue(PdfRow::find($marker['id'])->entered);
        $this->deleteJson(route('mdb-workflow.entry.rows.destroy', [$this->batch, $this->transformer, $row]), [
            'revision' => $this->batch->fresh()->revision, 'confirmed' => true,
        ])->assertOk();
        $this->assertFalse(PdfRow::find($marker['id'])->entered);
        $this->assertNull(PdfRow::find($marker['id'])->entry_row_id);
    }

    public function test_pdf_layout_and_markers_reject_foreign_batches_invalid_bounds_and_stale_changes(): void
    {
        $marker = $this->pdfLayout()[0];
        $foreign = SurveyBatch::create(['project_id' => $this->batch->project_id, 'feeder_id' => $this->batch->feeder_id, 'survey_team_id' => $this->batch->survey_team_id,
            'survey_date' => '2026-10-08', 'created_by' => $this->admin->id]);
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$foreign, $marker['id']]), ['revision' => 0, 'entered' => false])->assertNotFound();
        $this->putJson(route('mdb-workflow.entry.pdf.layout', [$foreign, $this->pdf]), [])->assertNotFound();
        $this->putJson(route('mdb-workflow.entry.pdf.layout', [$this->batch, $this->pdf]), [
            'revision' => $this->batch->fresh()->revision, 'page' => 15, 'row_count' => 3,
            'bounds' => ['x' => .9, 'y' => 0, 'width' => 1, 'height' => 1],
        ])->assertUnprocessable();
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$this->batch, $marker['id']]), ['revision' => -1, 'entered' => false])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => UserRole::ManagementViewer]));
        $this->putJson(route('mdb-workflow.entry.pdf.mark', [$this->batch, $marker['id']]), ['revision' => 1, 'entered' => false])->assertForbidden();
    }

    public function test_pdf_row_save_retries_are_idempotent_and_do_not_mark_a_different_row(): void
    {
        $markers = $this->pdfLayout();
        $payload = $this->payload(['pdf_progress_row_id' => $markers[0]['id']]);
        $url = route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]);
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $payload['pdf_progress_row_id'] = $markers[1]['id'];
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame(1, $this->transformer->entryRows()->count());
        $this->assertFalse(PdfRow::find($markers[1]['id'])->entered);
    }

    public function test_existing_records_can_be_linked_to_physical_rows_without_duplication(): void
    {
        $this->entrySettings();
        $row = $this->save(['identity_version' => 2]);
        $markers = $this->pdfLayout();
        $url = route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]);
        $this->putJson($url, $this->payload(['identity_version' => 2, 'client_uuid' => $row->client_uuid,
            'pdf_progress_row_id' => $markers[0]['id']]))->assertOk();
        $this->assertSame(1, $this->transformer->entryRows()->count());
        $this->assertSame('PDF row 1', $row->fresh()->source_row);
        $this->assertSame(1, $row->fresh()->entry_sequence);
        $this->putJson($url, $this->payload(['identity_version' => 2, 'client_uuid' => $row->client_uuid,
            'pdf_progress_row_id' => $markers[1]['id']]))->assertUnprocessable();
        $this->assertSame($row->id, PdfRow::find($markers[0]['id'])->entry_row_id);
        $this->assertNull(PdfRow::find($markers[1]['id'])->entry_row_id);
    }

    public function test_complete_identity_and_automatic_metadata_are_saved_once_with_project_unit(): void
    {
        $this->entrySettings();
        $payload = $this->payload(['identity_version' => 2, 'gpx_source_id' => null, 'source_pdf_id' => null, 'source_page' => 2, 'source_row' => 'fabricated PDF row', 'pole_height_unit' => 'm']);
        $url = route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('pole_height_unit');
        $payload['pole_height_unit'] = null;
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $row = $this->transformer->entryRows()->sole();
        $this->assertSame('01081026001', $row->composite_identifier);
        $this->assertSame('001', $row->waypoint_reference);
        $this->assertSame('ft', $row->pole_height_unit);
        $this->assertSame(1, $row->entry_sequence);
        $this->assertSame('Entry 1', $row->source_row);
        $this->assertSame(2, $row->source_page);
        $this->assertSame($this->pdf->id, $row->source_pdf_id);
        $this->assertSame($this->gpx->id, $row->gpx_source_id);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'waypoint_reference' => '002']);
        $this->assertSame(2, $end->entry_sequence);
        $this->assertSame('01081026002', $end->composite_identifier);
    }

    public function test_pasted_examples_and_mixed_se_dates_do_not_use_the_header_date(): void
    {
        $this->entrySettings();
        $start = $this->save(['identity_version' => 2, 'composite_identifier' => '11131222104', 'group_number' => null, 'row_date' => null, 'waypoint_reference' => null]);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'composite_identifier' => '01081026001', 'group_number' => null, 'row_date' => null, 'waypoint_reference' => null]);
        $this->assertSame('2022-12-13', $start->row_date->format('Y-m-d'));
        $this->assertSame('2026-10-08', $end->row_date->format('Y-m-d'));
        $this->assertSame('11', $start->group_number);
        $this->assertSame('104', $start->waypoint_reference);
        $this->assertSame('2026-10-08', $this->transformer->fresh()->header['survey_date']);
        $section = $this->transformer->sections()->sole();
        $this->assertSame('11131222104', $section->original_entry['start_survey_identifier']);
        $this->assertSame('01081026001', $section->original_entry['end_survey_identifier']);
    }

    public function test_invalid_composites_and_mismatched_components_are_rejected(): void
    {
        $this->entrySettings();
        foreach (['0108102600', '010810260001', '01310226001'] as $identifier) {
            $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload(['identity_version' => 2,
                'composite_identifier' => $identifier, 'group_number' => null, 'row_date' => null, 'waypoint_reference' => null]))->assertUnprocessable();
        }
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload(['identity_version' => 2, 'composite_identifier' => '01081026002']))->assertUnprocessable();
        $this->assertDatabaseCount('mdb_workflow_entry_rows', 0);
    }

    public function test_continuation_inherits_only_omitted_components_and_keeps_an_older_date(): void
    {
        $this->entrySettings();
        $start = $this->save(['identity_version' => 2, 'row_date' => '2022-12-13']);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'waypoint_reference' => '002', 'group_number' => null, 'row_date' => null, 'inheritance' => ['group_date' => $start->id]]);
        $this->assertSame('01131222002', $end->composite_identifier);
        $this->assertSame('2022-12-13', $end->row_date->format('Y-m-d'));
        $this->assertSame($start->id, $end->inheritance['group_date']);
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload(['identity_version' => 2, 'pair_number' => 2,
            'group_number' => '02', 'inheritance' => ['group_date' => $start->id]]))->assertUnprocessable();
    }

    public function test_repeated_gps_numbers_resolve_by_date_context_and_keep_distinct_network_nodes(): void
    {
        $this->entrySettings();
        $this->gpx->waypoints()->where('name', '001')->update(['recorded_at' => '2026-10-08 08:00:00']);
        $older = SourceFile::create(['batch_id' => $this->batch->id, 'kind' => 'gpx', 'path' => 'older.gpx', 'original_name' => 'older.gpx', 'sha256' => str_repeat('e', 64), 'bytes' => 9, 'mime' => 'application/gpx+xml', 'uploaded_by' => $this->admin->id, 'status' => 'ready']);
        $oldPoint = $older->waypoints()->create(['batch_id' => $this->batch->id, 'name' => '001', 'recorded_at' => '2022-12-13 08:00:00', 'latitude' => 30.1, 'longitude' => 71]);
        $this->transformer->update(['source_waypoint_id' => $oldPoint->id]);
        $start = $this->save(['identity_version' => 2, 'row_date' => '2022-12-13', 'gpx_source_id' => null]);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'gpx_source_id' => null]);
        $this->assertSame($older->id, $start->gpx_source_id);
        $this->assertSame($this->gpx->id, $end->gpx_source_id);
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $points = array_column($snapshot['waypoints'], null, 'id');
        $network = app(SurveyWaypoint::class)->network($snapshot['transformers'][0], $points);
        $this->assertSame('survey:01131222001', $network['sections'][0]['start_waypoint_id']);
        $this->assertSame('survey:01081026001', $network['sections'][0]['end_waypoint_id']);
        $projection = $this->mock(ProjectionService::class);
        $projection->shouldReceive('projectMany')->andReturnUsing(fn ($points) => array_map(fn ($p) => ['x' => $p['longitude'] * 1000, 'y' => $p['latitude'] * 1000], $points));
        $projection->shouldReceive('project')->andReturn(['x' => 71000, 'y' => 30000]);
        $validation = app(NetworkValidator::class)->validate($snapshot);
        $this->assertNotContains('self_connected_section', array_column($validation['errors'], 'code'));
        $this->assertNotContains('ambiguous_waypoint', array_column($validation['errors'], 'code'));
        $this->assertCount(2, $validation['topology'][0]['nodes']);
    }

    public function test_repeated_gps_names_within_one_source_resolve_by_recording_date(): void
    {
        $this->entrySettings();
        $this->gpx->waypoints()->where('name', '001')->update(['recorded_at' => '2026-10-08 08:00:00']);
        $oldPoint = $this->gpx->waypoints()->create(['batch_id' => $this->batch->id, 'name' => '001', 'recorded_at' => '2022-12-13 08:00:00', 'latitude' => 30.1, 'longitude' => 71]);
        $this->transformer->update(['source_waypoint_id' => $oldPoint->id]);
        $this->save(['identity_version' => 2, 'row_date' => '2022-12-13']);
        $this->save(['identity_version' => 2, 'designation' => 'E']);
        $validation = app(NetworkValidator::class)->validate($this->batch->fresh());
        $this->assertCount(2, $validation['topology'][0]['nodes']);
        $codes = array_column($validation['errors'], 'code');
        $this->assertNotContains('conflicting_coordinates', $codes);
        $this->assertNotContains('ambiguous_waypoint', $codes);
        $this->assertNotContains('self_connected_section', $codes);
        $this->assertContains('repeated_waypoint_across_dates', array_column($validation['warnings'], 'code'));
    }

    public function test_unknown_context_keeps_ambiguous_matches_in_a_draft_without_guessed_coordinates(): void
    {
        $this->entrySettings();
        $other = SourceFile::create(['batch_id' => $this->batch->id, 'kind' => 'gpx', 'path' => 'other.gpx', 'original_name' => 'other.gpx', 'sha256' => str_repeat('c', 64), 'bytes' => 9, 'mime' => 'application/gpx+xml', 'uploaded_by' => $this->admin->id, 'status' => 'ready']);
        $other->waypoints()->create(['batch_id' => $this->batch->id, 'name' => '001', 'latitude' => 30.5, 'longitude' => 71]);
        $row = $this->save(['identity_version' => 2, 'gpx_source_id' => null]);
        $this->assertNull($row->gpx_source_id);
        $this->assertContains('entry_ambiguous_waypoint', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
        $section = app(SnapshotService::class)->snapshot($this->batch->fresh())['transformers'][0]['sections'][0];
        $this->assertNull($section['start_waypoint_id']);
    }

    public function test_compact_markup_removes_routine_source_fields_and_separate_search_boxes(): void
    {
        $this->entrySettings();
        $html = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->getContent();
        $this->assertStringNotContainsString('Search conductor', $html);
        $this->assertStringNotContainsString('PDF row number / reference', $html);
        $this->assertStringNotContainsString('name="pole_height_unit"><option', $html);
        $this->assertStringContainsString('Paste Complete Waypoint', $html);
        $this->assertStringContainsString('Date (DD/MM/YYYY)', $html);
        $this->assertStringContainsString('S - Start', $html);
        $this->assertStringContainsString('E - End', $html);
        $this->assertStringNotContainsString('S ? Start', $html);
        $this->assertStringNotContainsString('E ? End', $html);
        $this->assertStringContainsString('id="sidebar-collapse-toggle"', $html);
        $this->assertStringContainsString('Hide menu', $html);
        $this->assertStringContainsString('entry-electrical-equipment-fields', $html);
        $this->assertStringContainsString('Phase / conductors / equipment', $html);
        $this->assertStringContainsString('name="pole_height" type="number"', $html);
        $this->assertSame(1, substr_count($html, 'class="entry-compact-fieldset entry-electrical-equipment"'));
        $this->assertSame(6, substr_count($html, 'data-compact-select'));
        $this->assertStringNotContainsString('entry-copy-endpoint', $html);
        $columns = ['name="designation"', 'name="group_number"', 'name="row_date"', 'name="waypoint_reference"', 'id="entry-phase"',
            'name="conductors[R]"', 'name="conductors[Y]"', 'name="conductors[B]"', 'name="conductors[N]"', 'name="equipment_type"', 'name="pole_class"', 'name="pole_height"',
            ...array_map(fn ($key) => 'name="consumers['.$key.']"', array_keys(EntryLookups::CONSUMERS)), 'name="intersection"', 'id="entry-pv-records"'];
        $positions = array_map(fn ($column) => strpos($html, $column), $columns);
        $ordered = $positions;
        sort($ordered);
        $this->assertSame($ordered, $positions);
        $this->get(route('mdb-workflow.config', $this->batch->project_id))->assertOk()->assertSee('Survey two-digit-year window starts at')->assertSee('Verified survey pole-height unit');
    }

    public function test_row_validation_returns_specific_field_messages_without_saving_or_marking_progress(): void
    {
        $marker = $this->pdfLayout()[0];
        $response = $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload([
            'pdf_progress_row_id' => $marker['id'], 'row_date' => '2026-02-31', 'pole_height' => -1,
            'consumers' => ['rs' => 1.5, 'rl' => -1], 'conductors' => ['R' => 'invalid', 'Y' => '__none__', 'B' => '__none__', 'N' => '__none__'],
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['row_date', 'pole_height', 'consumers.rs', 'consumers.rl', 'conductors.R']);
        $this->assertStringContainsString('valid calendar date', $response->json('errors.row_date.0'));
        $this->assertStringContainsString('RS consumer count must be a whole number', $response->json('errors')['consumers.rs'][0]);
        $this->assertStringContainsString('RL consumer count cannot be negative', $response->json('errors')['consumers.rl'][0]);
        $this->assertStringContainsString('listed conductor', $response->json('errors')['conductors.R'][0]);
        $this->assertSame(0, $this->transformer->entryRows()->count());
        $this->assertFalse(PdfRow::find($marker['id'])->entered);
    }

    public function test_draft_missing_waypoint_and_none_states_survive_reload_and_duplicate_clicks_are_idempotent(): void
    {
        $payload = $this->payload(['waypoint_reference' => '0001', 'conductors' => ['R' => 'A', 'Y' => null, 'B' => '__none__', 'N' => '__none__'], 'consumers' => ['rs' => 0], 'intersection' => true]);
        $url = route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]);
        $this->postJson($url, $payload)->assertOk();
        $revision = $this->batch->fresh()->revision;
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('revision', $revision);
        $row = EntryRow::firstOrFail();
        $this->assertSame('0001', $row->waypoint_reference);
        $this->assertSame(['R' => 'A', 'Y' => null, 'B' => '', 'N' => ''], $row->conductors);
        $this->assertSame(0, $row->consumers['rs']);
        $this->assertNull($row->consumers['rl']);
        $this->assertTrue($row->intersection);
        $this->assertDatabaseCount('mdb_workflow_entry_rows', 1);
        $this->assertDatabaseCount('mdb_workflow_sections', 1);
        $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()->assertJsonPath('transformers.0.rows.0.waypoint_reference', '0001')->assertJsonPath('transformers.0.rows.0.conductors.Y', null);
        $codes = array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code');
        $this->assertContains('incomplete_entry_pair', $codes);
        $this->assertContains('entry_missing_waypoint', $codes);
        $this->assertContains('incomplete_entry_conductors', $codes);
        $this->postJson($url, array_replace($payload, ['waypoint_reference' => '010']))->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_paired_rows_preserve_individual_poles_pv_decimal_references_and_count_each_entry_once(): void
    {
        $start = $this->save(['consumers' => ['rs' => 2, 'rl' => 0], 'pole_height' => 36, 'equipment_type' => 'TR', 'intersection' => true]);
        $end = $this->save(['designation' => 'E', 'waypoint_reference' => '010', 'source_row' => '2', 'consumers' => ['rs' => 3, 'pv' => 1],
            'pv_details' => [['reference' => '0000123', 'service_load_kw' => 2.25, 'installed_capacity_kw' => 3.5, 'remarks' => 'Source observation']]]);
        $section = $start->fresh()->section;
        $this->assertSame(['R', 'N'], $section->phases);
        $this->assertSame('ANT', $section->conductors['R']);
        $this->assertSame('', $section->conductors['Y']);
        $this->assertSame('001', $section->start_reference);
        $this->assertSame('010', $section->end_reference);
        $this->assertSame(5, $section->consumers()->where('category', 'rs')->first()->count);
        $this->assertSame(0, $section->consumers()->where('category', 'rl')->first()->count);
        $this->assertNull($section->consumers()->where('category', 'sc')->first()->count);
        $this->assertSame(9, $section->consumers()->count());
        $this->assertSame(36.0, $start->fresh()->pole_height);
        $this->assertSame(31.0, $end->pole_height);
        $this->assertSame('TR', $start->equipment_type);
        $this->assertSame('SP', $end->equipment_type);
        $pv = $end->pvRecords()->firstOrFail();
        $this->assertSame('0000123', $pv->reference);
        $this->assertSame(2.25, $pv->service_load_kw);
        $this->assertSame(3.5, $pv->installed_capacity_kw);
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $this->assertCount(2, $snapshot['transformers'][0]['entry_rows']);
        $this->assertTrue($snapshot['transformers'][0]['entry_rows'][0]['intersection']);
        $this->assertSame(2.25, $snapshot['transformers'][0]['pv_records'][0]['service_load_kw']);
        $this->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('0000123')->assertSee('2.25')->assertSee('All S/E rows');
    }

    public function test_continuation_and_header_corrections_keep_one_transformer_and_new_transformer_is_separate(): void
    {
        $row = $this->save();
        $this->postJson(route('mdb-workflow.pages.store', [$this->batch, $this->transformer]), ['revision' => $this->batch->fresh()->revision, 'source_pdf_id' => $this->pdf->id, 'page' => 2, 'role' => 'continuation', 'confirmed' => true])->assertOk();
        $this->assertSame('T-entry', $this->transformer->fresh()->code);
        $this->assertCount(2, $this->transformer->fresh()->header['pages']);
        $header = ['revision' => $this->batch->fresh()->revision, 'code' => 'T-entry', 'capacity_kva' => 125, 'header' => ['location' => 'Corrected location', 'mounting' => 'Double Pole', 'service_category' => 'General Duty']];
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $header)->assertOk();
        $this->assertCount(2, $this->transformer->fresh()->header['pages']);
        $this->assertSame('000407', $this->transformer->fresh()->header['feeder_identifier']);
        $header['revision'] = $this->batch->fresh()->revision;
        $header['code'] = 'T-other';
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $header)->assertOk();
        $other = $this->batch->transformers()->where('code', 'T-other')->firstOrFail();
        $this->assertSame(0, $other->entryRows()->count());
        $this->assertSame(1, $this->transformer->entryRows()->count());
        $this->get(route('mdb-workflow.show', [$this->batch, 'transformer_id' => $other->id]))->assertOk();
        $this->assertSame($this->transformer->id, $row->fresh()->transformer_id);
    }

    public function test_confirmation_and_complete_pairs_are_required_for_review_but_not_draft_entry(): void
    {
        $this->save();
        $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision, 'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertSessionHasErrors('rows');
        $this->post(route('mdb-workflow.transition', $this->batch), ['revision' => $this->batch->fresh()->revision, 'action' => 'review'])->assertSessionHasErrors('rows');
        $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision, 'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($this->transformer->fresh()->header['manually_verified']);
        $this->assertSame(2, $this->transformer->entryRows()->where('manually_verified', true)->count());
        $this->assertTrue($this->transformer->sections()->first()->original_entry['manually_verified']);
    }

    public function test_row_edit_preserves_first_entry_invalidates_approval_and_retries_once(): void
    {
        $row = $this->save();
        $revision = app(SnapshotService::class)->capture($this->batch->fresh(), $this->admin->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $payload = $this->payload(['client_uuid' => $row->client_uuid, 'waypoint_reference' => '002']);
        $url = route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]);
        $this->putJson($url, array_replace($payload, ['revision' => 0]))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertNotNull($this->batch->fresh()->approved_revision_id);
        $this->putJson($url, $payload)->assertOk();
        $updatedRevision = $this->batch->fresh()->revision;
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('revision', $updatedRevision);
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->assertSame('001', $row->fresh()->original_entry['first_entry']['waypoint_reference']);
        $this->assertSame('002', $row->fresh()->waypoint_reference);
        $this->assertGreaterThan(1, AuditLog::where('action', 'mdb.entry_row_saved')->count());
    }

    public function test_delete_requires_confirmation_leaves_the_other_endpoint_in_the_same_pair(): void
    {
        $start = $this->save();
        $end = $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $url = route('mdb-workflow.entry.rows.destroy', [$this->batch, $this->transformer, $start]);
        $this->deleteJson($url, ['revision' => $this->batch->fresh()->revision])->assertUnprocessable();
        $this->deleteJson($url, ['revision' => $this->batch->fresh()->revision, 'confirmed' => true])->assertOk();
        $this->assertSame(1, $end->fresh()->pair_number);
        $this->assertNull($end->section->fresh()->start_reference);
        $this->assertSame('010', $end->section->fresh()->end_reference);
        $this->assertContains('incomplete_entry_pair', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
    }

    public function test_foreign_rows_sources_and_copy_provenance_cannot_cross_transformers(): void
    {
        $row = $this->save();
        $other = $this->batch->transformers()->create(['code' => 'T-other']);
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $other, $row]), $this->payload(['client_uuid' => $row->client_uuid]))->assertNotFound();
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $other]), $this->payload(['inheritance' => ['conductors' => $row->id]]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::ManagementViewer]));
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload())->assertForbidden();
    }

    public function test_operator_markup_places_pdf_above_header_and_rows_and_preserves_existing_sections(): void
    {
        $legacy = $this->transformer->sections()->create(['start_reference' => '878', 'end_reference' => '879', 'original_entry' => ['raw' => 'Existing source']]);
        $before = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $hash = app(SnapshotService::class)->hash($before);
        $response = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertSee('Save &amp; Add Next', false)->assertSee('A081026.pdf')->assertSee('Intersection (Int)');
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'id="entry-header-form"'), strpos($html, 'id="entry-pdf-canvas"'));
        $this->assertLessThan(strpos($html, 'id="entry-row-form"'), strpos($html, 'id="entry-header-form"'));
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            if (! is_dir(base_path('tmp/mdb-entry-qa'))) {
                mkdir(base_path('tmp/mdb-entry-qa'), 0777, true);
            }
            file_put_contents(base_path('tmp/mdb-entry-qa/operator.html'), $html);
        }
        $this->assertStringNotContainsString('name="phase"', $html);
        $this->assertStringNotContainsString('name="consumers[int]"', $html);
        $this->assertSame($hash, app(SnapshotService::class)->hash(app(SnapshotService::class)->snapshot($this->batch->fresh())));
        $this->assertArrayNotHasKey('entry_rows', $before['transformers'][0]);
        $this->get(route('mdb-workflow.advanced', $this->batch))->assertOk()->assertSee('878')->assertSee('879');
        $this->assertSame('Existing source', $legacy->fresh()->original_entry['raw']);
    }

    public function test_explicit_branch_pairs_flow_through_the_existing_writer_and_reopen_as_a_genuine_mdb(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! app(WindowsExporter::class)->configured()) {
            $this->markTestSkipped('Windows DAO/ACE writer required.');
        }
        // Synthetic, explicitly reviewed fixture only. These are never application defaults.
        foreach ([['S', '001', 1], ['E', '010', 1], ['S', '001', 2], ['E', '002', 2]] as [$designation,$reference,$pair]) {
            $this->save(['designation' => $designation, 'waypoint_reference' => $reference, 'pair_number' => $pair, 'consumers' => array_fill_keys(array_keys(EntryLookups::CONSUMERS), 0)]);
        }
        $projection = $this->mock(ProjectionService::class);
        $projection->shouldReceive('project')->andReturnUsing(fn ($lat, $lon) => ['x' => $lon * 1000, 'y' => $lat * 1000]);
        $projection->shouldReceive('length')->andReturn(12.5);
        $sectionFields = ['ConfigurationId' => 'reviewed-config', 'SectionPhases' => 'R  N', 'PhaseConductorId' => 'ANT', 'NeutralConductorId' => 'ANT', 'SectionLength_MUL' => 0];
        $mapping = ['version' => ExportPayloadBuilder::VERSION, 'reviewed_by' => $this->admin->id, 'reviewed_at' => now('UTC')->toIso8601String(),
            'table_values' => ['SAI_Control' => ['Frequency' => 50, 'LengthUnits' => 'Metric', 'Product' => 'SynerGEE Electric 5.0.0.324', 'ProjectionFile' => 'fixture-crs'],
                'Node' => [], 'InstSection' => ['ConfigurationId' => 'reviewed-config'], 'InstFeeders' => ['SubstationId' => 'fixture-grid', 'NominalKvll' => 11], 'InstPrimaryTransformers' => [], 'Loads' => []],
            'networks' => ['T-entry' => ['source_nodes' => [['key' => 'source', 'waypoint_id' => $this->transformer->source_waypoint_id], ['key' => 'hv', 'waypoint_id' => $this->transformer->source_waypoint_id]],
                'source_sections' => [['key' => 'supply', 'from' => 'source', 'to' => 'hv', 'fields' => $sectionFields], ['key' => 'primary', 'from' => 'hv', 'to' => 'wp:'.$this->transformer->source_waypoint_id, 'fields' => $sectionFields]],
                'feeder_node' => 'source', 'transformer_section' => 'primary', 'transformer_fields' => ['TransformerType' => 'reviewed-100', 'ConnectedPhases' => 'R', 'SpecNomKv' => 11, 'UseInstanceImpedance' => 0]]]];
        Configuration::create(['project_id' => $this->batch->project_id, 'epsg' => 32642, 'settings' => ['frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'mapping' => $mapping],
            'load_assumptions' => ['method' => 'Explicit zero counts in synthetic fixture'], 'approved_by' => $this->admin->id, 'approved_at' => now('UTC')]);
        Storage::disk('local')->put('entry-template.mdb', file_get_contents(resource_path('mdb/synergee-empty.mdb')));
        Template::create(['code' => 'entry-fixture', 'version' => 'test-v1', 'path' => 'entry-template.mdb', 'sha256' => hash_file('sha256', resource_path('mdb/synergee-empty.mdb')),
            'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'metadata' => ['equipment_references' => ['conductors' => ['ANT'], 'transformers' => ['reviewed-100'], 'configurations' => ['reviewed-config']]],
            'approved_by' => $this->admin->id, 'approved_at' => now('UTC'), 'active' => true]);
        $this->transformer->sections()->update(['equipment_ref' => 'reviewed-config']);
        $revision = app(SnapshotService::class)->capture($this->batch->fresh(), $this->admin->id);
        $this->batch->update(['status' => 'approved', 'approved_revision_id' => $revision->id]);
        $this->app->instance(Exporter::class, app(WindowsExporter::class));
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch->fresh(), $this->admin, $this->transformer->id);
        $payload = json_decode(Storage::disk('local')->get($job->payload_path), true);
        $this->assertSame($payload['tables']['InstSection'][2]['FromNodeId'], $payload['tables']['InstSection'][3]['FromNodeId']);
        $this->assertNotSame($payload['tables']['InstSection'][2]['ToNodeId'], $payload['tables']['InstSection'][3]['ToNodeId']);
        $this->assertCount(4, $payload['entry_rows']);
        $this->assertSame('R  N', $payload['tables']['InstSection'][2]['SectionPhases']);
        $this->assertSame('ANT', $payload['tables']['InstSection'][2]['NeutralConductorId']);
        $exports->run($job->id);
        $job->refresh();
        $this->assertSame('generated', $job->status);
        $bytes = Storage::disk('local')->get($job->output_path);
        $this->assertStringContainsString('Standard Jet DB', substr($bytes, 0, 32));
        $this->assertSame(hash('sha256', $bytes), $job->output_sha256);
        $this->assertSame(1, $job->attempts);
        $this->assertSame('generated', $job->status);
        $this->assertDatabaseCount('mdb_workflow_entry_rows', 4);
    }
}
