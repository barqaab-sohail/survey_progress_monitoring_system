<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\PdfLine;
use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\Transformer;
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

    private Transformer $registeredTransformer;

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
        foreach (['T-entry', 'T-new-header', 'T-other'] as $code) {
            $registered = Transformer::create(['feeder_id' => $feeder->id, 'transformer_code' => $code, 'capacity_kva' => 100, 'latitude' => 30.001, 'longitude' => 71.001]);
            if ($code === 'T-new-header') {
                $this->registeredTransformer = $registered;
            }
        }
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

    public function test_se_rows_require_group_gps_type_class_and_height(): void
    {
        foreach (['group_number', 'waypoint_reference', 'equipment_type', 'pole_class', 'pole_height'] as $field) {
            foreach ([null, '', '   '] as $blank) {
                $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
                    $this->payload([$field => $blank]))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertSame(0, $this->transformer->entryRows()->count());
    }

    public function test_single_phase_form_has_labelled_phase_inputs_and_editable_totals(): void
    {
        $html = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->getContent();
        foreach (EntryLookups::SINGLE_PHASE_CONSUMERS as $category) {
            foreach (['R', 'Y', 'B'] as $phase) {
                $this->assertStringContainsString('name="consumer_phases['.$category.']['.$phase.']"', $html);
                $this->assertStringContainsString('aria-label="'.strtoupper($category).' '.$phase.' phase consumer count"', $html);
            }
            $this->assertStringNotContainsString('name="consumers['.$category.']" type="number" readonly', $html);
        }
        foreach (['rl', 'lc', 'si', 'li', 'pb', 'ag', 'pv'] as $category) {
            $this->assertStringNotContainsString('name="consumer_phases['.$category.']', $html);
        }
    }

    public function test_si_saves_as_one_three_phase_consumer_count(): void
    {
        $row = $this->save(['consumers' => ['si' => 8]]);
        $this->assertSame(8, $row->fresh()->consumers['si']);
        $this->assertArrayNotHasKey('si', $row->original_entry['consumer_phases'] ?? []);
    }

    public function test_clearing_pv_count_removes_blank_pv_details_and_projected_records(): void
    {
        $blank = ['reference' => null, 'meter_number' => null, 'service_load_kw' => null, 'installed_capacity_kw' => null, 'remarks' => null];
        $row = $this->save(['consumers' => ['pv' => 1], 'pv_details' => [$blank]]);
        $this->assertCount(1, $row->pvRecords);
        foreach ([null, 0] as $count) {
            $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]),
                $this->payload(['client_uuid' => $row->client_uuid, 'consumers' => ['pv' => $count], 'pv_details' => [$blank]]))->assertOk();
            $this->assertSame([], $row->fresh()->pv_details);
            $this->assertSame(0, $row->pvRecords()->count());
        }
    }

    public function test_single_phase_counts_save_reload_edit_and_keep_scalar_export_totals(): void
    {
        $phases = ['rs' => ['R' => 5, 'Y' => 2, 'B' => 7], 'sc' => ['R' => 0, 'Y' => null, 'B' => 3],
            'st' => ['R' => 1, 'Y' => null, 'B' => 0]];
        $row = $this->save(['consumer_phases' => $phases, 'consumers' => ['rs' => 999, 'rl' => 4, 'sc' => 999, 'pv' => 2]]);
        $this->assertSame($phases, $row->original_entry['consumer_phases']);
        $this->assertSame(14, $row->consumers['rs']);
        $this->assertSame(3, $row->consumers['sc']);
        $this->assertNull($row->consumers['si']);
        $this->assertSame(1, $row->consumers['st']);
        $this->assertSame(4, $row->consumers['rl']);
        $this->assertSame(2, $row->consumers['pv']);
        $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()
            ->assertJsonPath('transformers.0.rows.0.consumer_phases', $phases);

        $phases['rs'] = ['R' => 0, 'Y' => null, 'B' => null];
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]),
            $this->payload(['client_uuid' => $row->client_uuid, 'consumer_phases' => $phases,
                'consumers' => ['rs' => 14, 'rl' => 4, 'pv' => 2]]))->assertOk();
        $row->refresh();
        $this->assertSame(0, $row->consumers['rs']);
        $this->assertNull($row->original_entry['consumer_phases']['rs']['Y']);
        $this->assertSame(0, $row->original_entry['consumer_phases']['rs']['R']);
        $this->assertSame(0, $row->section->consumers()->where('category', 'rs')->value('count'));
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $this->assertSame($phases, $snapshot['transformers'][0]['entry_rows'][0]['consumer_phases']);
        $this->assertSame(9, $row->section->consumers()->count()); // PV still uses its separate path.
    }

    public function test_single_phase_legacy_totals_are_preserved_without_inventing_allocation(): void
    {
        $row = $this->save(['consumers' => ['rs' => 14, 'st' => 0]]);
        $this->assertArrayNotHasKey('consumer_phases', $row->surveyData());
        $url = route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]);
        $this->putJson($url, $this->payload(['client_uuid' => $row->client_uuid, 'pole_height' => 36,
            'consumers' => ['rs' => 14, 'st' => 0]]))->assertOk();
        $this->assertSame(14, $row->fresh()->consumers['rs']);
        $this->assertSame(0, $row->fresh()->consumers['st']);
        $this->assertArrayNotHasKey('consumer_phases', $row->fresh()->surveyData());

        $phases = ['rs' => ['R' => 5, 'Y' => 2, 'B' => 7]];
        $this->putJson($url, $this->payload(['client_uuid' => $row->client_uuid,
            'consumer_phases' => $phases, 'consumers' => ['rs' => 14, 'st' => 0]]))->assertOk();
        // An older client omitting phase metadata must retain the recorded allocation.
        $this->putJson($url, $this->payload(['client_uuid' => $row->client_uuid,
            'consumers' => ['rs' => 999, 'st' => 0]]))->assertOk();
        $this->assertSame(14, $row->fresh()->consumers['rs']);
        $this->assertSame($phases, $row->fresh()->surveyData()['consumer_phases']);
    }

    public function test_single_phase_blanks_zeros_and_validation(): void
    {
        $row = $this->save(['consumer_phases' => ['rs' => ['R' => '', 'Y' => null, 'B' => ''],
            'st' => ['R' => '0', 'Y' => '', 'B' => null]], 'consumers' => ['rs' => 14]]);
        $this->assertSame(14, $row->consumers['rs']);
        $this->assertSame(0, $row->consumers['st']);
        $this->assertSame(['R' => null, 'Y' => null, 'B' => null], $row->surveyData()['consumer_phases']['rs']);
        foreach ([-1, 1.5, 1000001] as $invalid) {
            $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
                $this->payload(['consumer_phases' => ['rs' => ['R' => $invalid, 'Y' => null, 'B' => null]]]))
                ->assertUnprocessable()->assertJsonValidationErrors('consumer_phases.rs.R');
        }
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
            $this->payload(['consumer_phases' => ['rs' => ['R' => 1000000, 'Y' => 1, 'B' => null]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('consumer_phases.rs');
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]),
            $this->payload(['consumer_phases' => ['rl' => ['R' => 1, 'Y' => 1, 'B' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('consumer_phases');
    }

    public function test_single_phase_totals_can_be_entered_without_allocation_and_replaced_after_clearing_phases(): void
    {
        $blank = ['R' => null, 'Y' => null, 'B' => null];
        $row = $this->save(['consumers' => ['rs' => 14, 'sc' => 0, 'si' => null, 'st' => 3],
            'consumer_phases' => array_fill_keys(['rs', 'sc', 'st'], $blank)]);
        $this->assertSame(14, $row->consumers['rs']);
        $this->assertSame(0, $row->consumers['sc']);
        $this->assertNull($row->consumers['si']);
        $this->assertSame(3, $row->consumers['st']);
        $url = route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]);
        $this->putJson($url, $this->payload(['client_uuid' => $row->client_uuid, 'consumers' => ['rs' => 99],
            'consumer_phases' => ['rs' => ['R' => 5, 'Y' => 2, 'B' => 7]]]))->assertOk();
        $this->assertSame(14, $row->fresh()->consumers['rs']);
        foreach ([25, 0, null] as $total) {
            $this->putJson($url, $this->payload(['client_uuid' => $row->client_uuid, 'consumers' => ['rs' => $total],
                'consumer_phases' => ['rs' => $blank]]))->assertOk();
            $this->assertSame($total, $row->fresh()->consumers['rs']);
            $this->assertSame($blank, $row->fresh()->surveyData()['consumer_phases']['rs']);
            $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()
                ->assertJsonPath('transformers.0.rows.0.consumers.rs', $total);
        }
    }

    private function entrySettings(): void
    {
        Configuration::create(['project_id' => $this->batch->project_id, 'settings' => ['entry' => ['two_digit_year_start' => 2000, 'pole_height_unit' => 'ft']]]);
    }

    public function test_data_entry_operator_can_create_batch_for_feeder_actively_assigned_to_their_team(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator, 'status' => 'active']);
        $team = $this->batch->surveyTeam;
        $feeder = $this->batch->feeder;
        $team->members()->attach($operator->id);
        FeederAssignment::create(['feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'assigned_by' => $this->admin->id,
            'start_date' => today()->subDay(), 'status' => 'active']);

        $this->actingAs($operator)->get(route('mdb-workflow.create'))
            ->assertOk()->assertSee('data-team-ids="'.$team->id.'"', false);

        $unassignedFeeder = $feeder->replicate();
        $unassignedFeeder->feeder_code = 'NOT-ALLOCATED';
        $unassignedFeeder->save();
        $this->from(route('mdb-workflow.create'))->post(route('mdb-workflow.store'), [
            'project_id' => $this->batch->project_id,
            'feeder_id' => $unassignedFeeder->id,
            'survey_team_id' => $team->id,
            'survey_date' => today()->toDateString(),
            'survey_pdf' => UploadedFile::fake()->createWithContent('invalid.pdf', "%PDF-1.4\nfixture"),
            'gps_gpx' => UploadedFile::fake()->createWithContent('invalid.gpx', '<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="30.001" lon="71.001"><name>001</name></wpt></gpx>'),
        ])->assertRedirect(route('mdb-workflow.create'))->assertSessionHasErrors('feeder_id');
        $this->assertDatabaseMissing('mdb_workflow_batches', ['created_by' => $operator->id]);

        $response = $this->post(route('mdb-workflow.store'), [
            'project_id' => $this->batch->project_id,
            'feeder_id' => $feeder->id,
            'survey_team_id' => $team->id,
            'survey_date' => today()->toDateString(),
            'survey_pdf' => UploadedFile::fake()->createWithContent('new-survey.pdf', "%PDF-1.4\nfixture"),
            'gps_gpx' => UploadedFile::fake()->createWithContent('new-survey.gpx', '<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="30.001" lon="71.001"><name>001</name></wpt></gpx>'),
        ]);

        $response->assertRedirect();
        $created = SurveyBatch::where('created_by', $operator->id)->latest('id')->firstOrFail();
        $this->assertSame('entry', $created->survey_status);
        $this->assertDatabaseCount('mdb_workflow_sources', 4);
        $this->get(route('mdb-workflow.show', $created))->assertOk();
    }

    private function headerPayload(array $changes = []): array
    {
        return array_replace(['revision' => $this->batch->fresh()->revision, 'registered_transformer_id' => $this->registeredTransformer->id, 'capacity_kva' => 100,
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
            'registered_transformer_id' => Transformer::where('feeder_id', $this->batch->feeder_id)->where('transformer_code', $this->transformer->code)->value('id'), 'capacity_kva' => -100,
            'header' => ['survey_date' => 'not a date', 'source_survey_identifier' => '123'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['code', 'capacity_kva', 'header.survey_date', 'header.source_survey_identifier']);
        $this->assertStringContainsString('already added', $response->json('errors.code.0'));
        $this->assertStringContainsString('11-digit', $response->json('errors')['header.source_survey_identifier'][0]);
        $this->assertTrue(Str::isUuid($response->json('reference')));
        $this->assertSame($revision, $this->batch->fresh()->revision);
        $this->assertSame(1, $this->batch->transformers()->count());
        Log::shouldHaveReceived('warning')->once()->with('Survey header save rejected', \Mockery::on(fn ($context) => $context['reference'] === $response->json('reference') && $context['http_status'] === 422 && isset($context['exception'])));
    }

    public function test_first_staged_header_accepts_optional_client_information_on_create_and_update(): void
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
            'source_pdf_id' => $batch->sources()->where('kind', 'pdf')->value('id'), 'header' => ['client_transformer_information' => '', 'location' => 'Retain this location'],
        ]))->assertOk();
        $this->assertSame(1, $batch->transformers()->count());
        $transformer = $batch->transformers()->firstOrFail();
        $this->assertNull($transformer->header['client_transformer_information']);
        $this->assertSame('Retain this location', $transformer->header['location']);

        $this->putJson(route('mdb-workflow.entry.headers.update', [$batch, $transformer]), $this->headerPayload([
            'revision' => $batch->fresh()->revision, 'source_pdf_id' => $batch->sources()->where('kind', 'pdf')->value('id'),
            'header' => ['location' => 'Updated location'],
        ]))->assertOk();
        $this->assertSame('Updated location', $transformer->fresh()->header['location']);

        $this->putJson(route('mdb-workflow.entry.headers.update', [$batch, $transformer]), $this->headerPayload([
            'revision' => $batch->fresh()->revision, 'source_pdf_id' => $batch->sources()->where('kind', 'pdf')->value('id'),
            'header' => ['client_transformer_information' => 'Client asset reference ABC-123'],
        ]))->assertOk();
        $this->assertSame('Client asset reference ABC-123', $transformer->fresh()->header['client_transformer_information']);
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

    public function test_header_pdf_location_is_required_validated_and_opens_the_selected_transformer_page(): void
    {
        $url = route('mdb-workflow.entry.headers.store', $this->batch);
        foreach ([['source_pdf_id' => null], ['source_page' => null], ['source_page' => 0], ['source_page' => 1.5], ['source_page' => 15], ['source_pdf_id' => $this->gpx->id]] as $invalid) {
            $this->postJson($url, $this->headerPayload($invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $otherPdf = $this->pdf->replicate();
        $otherPdf->original_name = 'Second survey.pdf';
        $otherPdf->save();
        $response = $this->postJson($url, $this->headerPayload(['source_pdf_id' => $otherPdf->id, 'source_page' => 7]))->assertOk();
        $saved = $this->batch->transformers()->findOrFail($response->json('active_transformer_id'));
        $this->assertSame(7, $saved->header['pdf_start_page']);
        $this->assertSame($otherPdf->id, $saved->header['pdf_source_id']);
        $review = $this->get(route('mdb-workflow.review', [$this->batch, 'transformer_id' => $saved->id]))->assertOk()
            ->assertSee('Second survey.pdf / 7')->assertSee('#page=7', false);
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            file_put_contents(base_path('tmp/mdb-entry-qa/review-page-seven.html'), $review->getContent());
        }
        $this->get(route('mdb-workflow.review', [$this->batch, 'transformer_id' => $this->transformer->id]))->assertOk()
            ->assertSee('A081026.pdf / 1')->assertSee('#page=1', false);
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $saved]), $this->headerPayload([
            'source_pdf_id' => $otherPdf->id, 'source_page' => 8,
        ]))->assertOk();
        $this->get(route('mdb-workflow.review', [$this->batch, 'transformer_id' => $saved->id]))->assertOk()
            ->assertSee('Second survey.pdf / 8')->assertSee('#page=8', false);
    }

    public function test_new_header_uses_only_a_registered_transformer_from_its_feeder(): void
    {
        $otherFeeder = $this->batch->feeder->replicate();
        $otherFeeder->feeder_code = 'FOREIGN';
        $otherFeeder->save();
        $foreign = Transformer::create(['feeder_id' => $otherFeeder->id, 'transformer_code' => 'Foreign transformer', 'capacity_kva' => 100, 'latitude' => 30.001, 'longitude' => 71.001]);
        $data = $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()->json('registered_transformers');
        $this->assertContains($this->registeredTransformer->id, array_column($data, 'id'));
        $this->assertNotContains($foreign->id, array_column($data, 'id'));
        $url = route('mdb-workflow.entry.headers.store', $this->batch);
        foreach ([null, $foreign->id, 999999] as $id) {
            $this->postJson($url, $this->headerPayload(['registered_transformer_id' => $id, 'code' => 'Manual code']))
                ->assertUnprocessable()->assertJsonValidationErrors('registered_transformer_id');
        }
        $this->assertSame(1, $this->batch->transformers()->count());
        $response = $this->postJson($url, $this->headerPayload(['code' => 'Manual code']))->assertOk();
        $saved = $this->batch->transformers()->findOrFail($response->json('active_transformer_id'));
        $this->assertSame($this->registeredTransformer->transformer_code, $saved->code);
        $this->assertSame($this->registeredTransformer->id, $saved->header['registered_transformer_id']);
        $this->postJson($url, $this->headerPayload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(2, $this->batch->transformers()->count());
    }

    public function test_entry_uses_a_default_year_window_without_requiring_configuration_access(): void
    {
        $url = route('mdb-workflow.entry.data', $this->batch);
        $this->getJson($url)->assertOk()->assertJsonPath('entry_settings.two_digit_year_start', 2000);
        $response = $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload([
            'group_number' => null, 'row_date' => null, 'waypoint_reference' => null, 'composite_identifier' => '11131222104', 'gpx_source_id' => null,
        ]))->assertOk();
        $row = $this->transformer->entryRows()->firstOrFail();
        $this->assertSame('2022-12-13', $row->row_date->format('Y-m-d'));
        $this->assertSame('11131222104', $row->composite_identifier);
        Configuration::create(['project_id' => $this->batch->project_id, 'settings' => ['entry' => ['two_digit_year_start' => 1970]]]);
        $this->getJson($url)->assertOk()->assertJsonPath('entry_settings.two_digit_year_start', 1970);
    }

    public function test_manual_transformer_can_be_saved_and_duplicates_cannot_be_added(): void
    {
        $url = route('mdb-workflow.entry.headers.store', $this->batch);
        $manual = ['manual_transformer' => true, 'registered_transformer_id' => null, 'code' => '  T-manual  '];
        $this->postJson($url, $this->headerPayload(array_replace($manual, ['code' => '  '])))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $response = $this->postJson($url, $this->headerPayload($manual))->assertOk();
        $saved = $this->batch->transformers()->findOrFail($response->json('active_transformer_id'));
        $this->assertSame('T-manual', $saved->code);
        $this->assertTrue($saved->header['manual_transformer']);
        $this->assertArrayNotHasKey('registered_transformer_id', $saved->header);
        foreach (['T-manual', ' t-MANUAL ', strtolower($this->transformer->code)] as $code) {
            $this->postJson($url, $this->headerPayload(array_replace($manual, ['code' => $code])))
                ->assertUnprocessable()->assertJsonValidationErrors('code')
                ->assertJsonPath('errors.code.0', 'This transformer is already added to this survey. Select its saved header in Active transformer to edit it.');
        }
        $this->postJson($url, $this->headerPayload(array_replace($manual, ['code' => strtolower($this->registeredTransformer->transformer_code)])))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $saved]), $this->headerPayload(['header' => ['location' => 'Updated manual location']]))->assertOk();
        $this->assertSame('Updated manual location', $saved->fresh()->header['location']);
        $this->assertSame(2, $this->batch->transformers()->count());
    }

    public function test_transformer_duplicates_are_blocked_across_batches_and_advanced_entry(): void
    {
        $other = $this->batch->replicate();
        $other->save();
        $other->refresh();
        $copy = $this->pdf->replicate();
        $copy->batch_id = $other->id;
        $copy->save();
        $beforeRevision = $other->revision;
        $url = route('mdb-workflow.entry.headers.store', $other);
        foreach (['T-entry', ' t-ENTRY ', 'T - entry', "T\u{2013}entry"] as $code) {
            $response = $this->postJson($url, $this->headerPayload([
                'manual_transformer' => true, 'registered_transformer_id' => null, 'code' => $code,
                'revision' => $other->fresh()->revision, 'source_pdf_id' => $copy->id,
            ]))->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertStringContainsString('batch #'.$this->batch->id, $response->json('errors.code.0'));
        }
        $this->assertSame(0, $other->transformers()->count());
        $this->assertSame($beforeRevision, $other->fresh()->revision);
        $this->postJson($url, $this->headerPayload([
            'revision' => $other->revision, 'source_pdf_id' => $copy->id,
            'registered_transformer_id' => Transformer::where('feeder_id', $other->feeder_id)->where('transformer_code', 'T-entry')->value('id'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post(route('mdb-workflow.transformers.store', $other), ['revision' => $other->revision, 'code' => 'T - entry'])
            ->assertSessionHasErrors('code');
        $existing = $other->transformers()->create(['code' => 'T-entry', 'header' => []]); // Historical duplicate stays editable.
        $this->putJson(route('mdb-workflow.entry.headers.update', [$other, $existing]), $this->headerPayload([
            'revision' => $other->fresh()->revision, 'source_pdf_id' => $copy->id, 'header' => ['location' => 'Corrected existing record'],
        ]))->assertOk();
        $this->assertSame('Corrected existing record', $existing->fresh()->header['location']);
        $different = $other->transformers()->create(['code' => 'Other code', 'header' => []]);
        $this->put(route('mdb-workflow.transformers.update', [$other, $different]),
            ['revision' => $other->fresh()->revision, 'code' => 'T - entry'])->assertSessionHasErrors('code');
        $this->assertSame('Other code', $different->fresh()->code);
    }

    public function test_transformer_number_can_be_used_for_a_different_project(): void
    {
        $project = $this->batch->project->replicate();
        $project->code = 'OTHER-PROJECT';
        $project->save();
        $other = $this->batch->replicate();
        $other->project_id = $project->id;
        $other->save();
        $other->refresh();
        $copy = $this->pdf->replicate();
        $copy->batch_id = $other->id;
        $copy->save();
        $this->postJson(route('mdb-workflow.entry.headers.store', $other), $this->headerPayload([
            'revision' => $other->revision, 'source_pdf_id' => $copy->id,
            'registered_transformer_id' => Transformer::where('feeder_id', $other->feeder_id)->where('transformer_code', 'T-entry')->value('id'),
        ]))->assertOk();
        $this->assertSame('T-entry', $other->transformers()->sole()->code);
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

    public function test_survey_review_and_submission_do_not_require_continuation_page_confirmation(): void
    {
        [$operator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $this->pdf->update(['metadata' => ['page_count' => 3]]);
        $this->transformer->entryRows()->update(['source_page' => 2]);
        $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'action' => 'submit'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('awaiting_verification', $this->batch->fresh()->survey_status);
        $this->transformer->entryRows()->update(['source_page' => 4]);
        $issues = app(EntryService::class)->issues($this->batch->fresh());
        $this->assertContains('invalid_entry_pdf_source', array_column($issues, 'code'));
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

    public function test_workflow_task_assignment_enforces_stage_roles_ownership_and_verification_separation(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $lead = User::factory()->create(['role' => UserRole::DataEntryVerificationTeamLeader]);
        $this->batch->surveyTeam->members()->attach($lead->id, ['is_leader' => true]);
        $url = route('team-work.workflow.assign');
        $this->actingAs($lead)->get(route('team-work.workflow'))->assertOk()->assertSee('Workflow Task Assignments');
        $data = ['batch_id' => $this->batch->id, 'stage' => 'entry', 'user_id' => $operator->id];
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($operator->id, $this->batch->fresh()->entry_assigned_to);
        $this->postJson($url, ['batch_id' => $this->batch->id, 'stage' => 'verification', 'user_id' => $operator->id])->assertUnprocessable();
        $this->batch->update(['survey_status' => 'awaiting_verification', 'entry_actor_ids' => [$verifier->id]]);
        $this->postJson($url, ['batch_id' => $this->batch->id, 'stage' => 'verification', 'user_id' => $verifier->id])->assertUnprocessable();
        $this->postJson($url, ['batch_id' => $this->batch->id, 'stage' => 'verification', 'user_id' => $creator->id])->assertStatus(422);
        $this->batch->update(['entry_actor_ids' => []]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
            ->post($url, ['batch_id' => $this->batch->id, 'stage' => 'verification', 'user_id' => $verifier->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($verifier->id, $this->batch->fresh()->verification_assigned_to);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.workflow_task_assigned']);
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

    public function test_operator_can_review_draft_values_and_correct_them_before_submission(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $page = $this->get(route('mdb-workflow.review', $this->batch))->assertOk()
            ->assertViewHas('canEdit', true)->assertViewHas('canCorrect', false)
            ->assertSee('id="operator-review-confirmation"', false);
        $html = $page->getContent();
        $this->assertStringContainsString('\u0022can_correct\u0022:true', $html);

        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) file_put_contents(base_path('tmp/mdb-entry-qa/operator-review.html'), $html);
        $stateUrl = route('mdb-workflow.verification.state', [$this->batch, $this->transformer]);
        $checkUrl = route('mdb-workflow.verification.check', [$this->batch, $this->transformer]);
        $state = $this->getJson($stateUrl)->assertOk()->json();
        $field = $state['header']['fields'][0];
        $body = ['revision' => $state['revision'], 'fields' => [['key' => $field['key'], 'hash' => $field['hash']]], 'checked' => true];
        $this->postJson($checkUrl, $body)->assertOk()->assertJsonPath('checked.0', $field['key']);
        $this->assertContains($field['key'], $this->getJson($stateUrl)->json('checked'));
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $this->headerPayload([
            'header' => ['inspector' => 'Operator corrected inspector'],
        ]))->assertOk();
        $this->assertSame('Operator corrected inspector', $this->transformer->fresh()->header['inspector']);
        $this->assertSame([], $this->getJson($stateUrl)->json('checked'));
        $this->assertContains($operator->id, $this->batch->fresh()->entry_actor_ids);
        $this->actingAs($creator)->postJson($checkUrl, $body)->assertForbidden();
        $this->actingAs($operator);
        $this->batch->update(['survey_status' => 'awaiting_verification']);
        $body['revision'] = $this->batch->fresh()->revision;
        $this->postJson($checkUrl, $body)->assertForbidden();
    }

    public function test_verifier_checks_persist_and_corrections_are_audited_and_invalidate_changed_values(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $this->post(route('mdb-workflow.survey.decision', $this->batch), ['revision'=>$this->batch->fresh()->revision,'action'=>'submit'])->assertSessionHasNoErrors();
        $this->actingAs($verifier);
        $page = $this->get(route('mdb-workflow.review',$this->batch))->assertOk()->assertSee('Entered survey - same layout as the PDF')->assertSee('Mark header checked &amp; hide',false)->assertSee('verification-editor',false);
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) file_put_contents(base_path('tmp/mdb-entry-qa/verifier-workspace.html'),$page->getContent());
        $stateUrl = route('mdb-workflow.verification.state',[$this->batch,$this->transformer]);
        $checkUrl = route('mdb-workflow.verification.check',[$this->batch,$this->transformer]);
        $state = $this->getJson($stateUrl)->assertOk()->json();
        $fields = fn($values) => array_map(fn($field)=>['key'=>$field['key'],'hash'=>$field['hash']],$values);
        $this->postJson($checkUrl,['revision'=>$state['revision'],'fields'=>$fields($state['header']['fields']),'checked'=>true])->assertOk();
        $start = $state['pairs'][0]['rows']['S'];
        $this->postJson($checkUrl,['revision'=>$state['revision'],'fields'=>$fields($start['fields']),'checked'=>true])->assertOk();
        $this->assertSame($state['revision'],$this->batch->fresh()->revision);
        $checked = $this->getJson($stateUrl)->assertOk()->json('checked');
        $this->assertCount(count($state['header']['fields'])+count($start['fields']),$checked);
        $url = route('mdb-workflow.verification.row',[$this->batch,$this->transformer,$start['id']]);
        $body = array_replace($start['data'],['revision'=>$state['revision'],'save_uuid'=>(string) Str::uuid(),'pole_height'=>37,'inheritance'=>[]]);
        $this->putJson($url,$body)->assertUnprocessable()->assertJsonValidationErrors('correction_reason');
        $body['conductors']=array_map(fn($value)=>$value === '' ? '__none__' : $value,$body['conductors']);
        $body['correction_reason']='Height reads 37 on the survey PDF';
        $this->putJson($url,array_replace($body,['revision'=>0]))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->putJson($url,$body)->assertOk();
        $this->assertSame(37.0,EntryRow::find($start['id'])->pole_height);
        $this->assertSame('awaiting_verification',$this->batch->fresh()->survey_status);
        $this->assertSame('awaiting_verification',$this->batch->fresh()->status);
        $this->assertNotContains($verifier->id,$this->batch->fresh()->entry_actor_ids);
        $after = $this->getJson($stateUrl)->assertOk()->json();
        $this->assertCount(count($state['header']['fields']),$after['checked']);
        $this->assertDatabaseHas('audit_logs',['user_id'=>$verifier->id,'action'=>'mdb.verifier_row_corrected','reason'=>$body['correction_reason']]);
        $this->postJson($checkUrl,['revision'=>$after['revision'],'fields'=>$fields($start['fields']),'checked'=>true])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->putJson(route('mdb-workflow.verification.header',[$this->batch,$this->transformer]),$this->headerPayload([
            'correction_reason'=>'Inspector name corrected against the PDF','header'=>['inspector'=>'Verified Inspector'],
        ]))->assertOk();
        $this->assertSame('Verified Inspector',$this->transformer->fresh()->header['inspector']);
        $this->assertSame([],$this->getJson($stateUrl)->assertOk()->json('checked'));
        $this->post(route('mdb-workflow.survey.decision',$this->batch),['revision'=>$this->batch->fresh()->revision,'action'=>'approve'])->assertSessionHasNoErrors();
        $this->putJson($url,array_replace($body,['revision'=>$this->batch->fresh()->revision]))->assertForbidden();
        $this->postJson($checkUrl,['revision'=>$this->batch->fresh()->revision,'fields'=>$fields($state['header']['fields']),'checked'=>true])->assertForbidden();
    }

    public function test_verifier_correction_routes_do_not_grant_entry_or_creator_permissions(): void
    {
        [$operator,$verifier,$creator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $this->post(route('mdb-workflow.survey.decision',$this->batch),['revision'=>$this->batch->fresh()->revision,'action'=>'submit'])->assertSessionHasNoErrors();
        $row=$this->transformer->entryRows()->firstOrFail();
        $url=route('mdb-workflow.verification.row',[$this->batch,$this->transformer,$row]);
        foreach([$operator,$creator] as $actor)$this->actingAs($actor)->putJson($url,['correction_reason'=>'Attempted correction'])->assertForbidden();
        $this->actingAs($verifier)->putJson(route('mdb-workflow.entry.rows.update',[$this->batch,$this->transformer,$row]),
            ['mdb_verifier_correction'=>true,'correction_reason'=>'Attempted entry bypass'])->assertForbidden();
        $foreign=$this->batch->transformers()->create(['code'=>'foreign test','header'=>[]]);
        $this->putJson(route('mdb-workflow.verification.row',[$this->batch,$foreign,$row]),['correction_reason'=>'Wrong transformer'])->assertNotFound();
        $state=$this->getJson(route('mdb-workflow.verification.state',[$this->batch,$this->transformer]))->assertOk()->json();
        $field=$state['header']['fields'][0];
        $this->postJson(route('mdb-workflow.verification.check',[$this->batch,$foreign]),['revision'=>$state['revision'],
            'fields'=>[['key'=>$field['key'],'hash'=>$field['hash']]],'checked'=>true])->assertUnprocessable();
        $this->assertDatabaseCount('mdb_review_checks',0);
    }

    public function test_three_stage_queues_counts_filters_and_reopening_permissions(): void
    {
        [$operator, $verifier, $creator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $url = route('mdb-workflow.survey.decision', $this->batch);
        $this->get(route('mdb-workflow.index', ['queue' => 'entry']))->assertOk()->assertViewHas('stageCounts', fn ($counts) => $counts['entry'] === 1 && $counts['draft'] === 1);
        $this->post($url, ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($verifier)->get(route('mdb-workflow.index', ['queue' => 'survey_verification']))->assertOk()
            ->assertSee('Pending Verification')->assertViewHas('stageCounts', fn ($counts) => $counts['entry'] === 0 && $counts['survey_verification'] === 1);
        $this->postJson($url, ['revision' => $this->batch->fresh()->revision, 'action' => 'flag'])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->post($url, ['revision' => $this->batch->fresh()->revision, 'action' => 'flag', 'remarks' => 'Check location on page 1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('awaiting_verification', $this->batch->fresh()->survey_status);
        $this->assertNull($this->batch->fresh()->survey_verified_at);
        $this->assertDatabaseHas('mdb_workflow_decisions', ['batch_id' => $this->batch->id, 'decision' => 'flag', 'decided_by' => $verifier->id]);
        $this->post($url, ['revision' => $this->batch->fresh()->revision, 'action' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($creator)->get(route('mdb-workflow.index', ['queue' => 'mdb_creation', 'status' => 'approved']))->assertOk()
            ->assertSee('Verified')->assertViewHas('batches', fn ($batches) => $batches->total() === 1);
        $reopen = route('mdb-workflow.reopen', $this->batch);
        $data = ['revision' => $this->batch->fresh()->revision, 'remarks' => 'Correct location'];
        $this->postJson($reopen, $data)->assertForbidden();
        $this->actingAs($verifier)->postJson($reopen, $data)->assertForbidden();
        $this->actingAs($operator)->postJson($reopen, ['revision' => 0, 'remarks' => 'Correct location'])->assertUnprocessable();
        $this->assertNotNull($this->batch->fresh()->approved_revision_id);
        $this->postJson($reopen, ['revision' => $data['revision']])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $outsider = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $this->actingAs($outsider)->postJson($reopen, $data)->assertForbidden();
        $this->actingAs($operator)->post($reopen, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Returned for Correction', $this->batch->fresh()->workflow_label);
        $this->assertNull($this->batch->fresh()->survey_verified_at);
        $this->actingAs($creator)->get(route('mdb-workflow.index', ['queue' => 'mdb_creation']))->assertOk()
            ->assertViewHas('batches', fn ($batches) => $batches->total() === 0);
        $this->actingAs($operator)->get(route('mdb-workflow.index', ['queue' => 'entry', 'status' => 'returned']))->assertOk()
            ->assertViewHas('batches', fn ($batches) => $batches->total() === 1);
        $this->actingAs($outsider)->get(route('mdb-workflow.index'))->assertOk()
            ->assertViewHas('stageCounts', fn ($counts) => array_sum($counts) === 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.survey_reopened', 'user_id' => $operator->id]);
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
        $operatorPage = $this->actingAs($operator)->get(route('mdb-workflow.show', $this->batch))->assertOk()->assertSee('Submit Transformer')->assertSee('Enter another transformer from the same PDF?')
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
        $this->actingAs($creator)->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertDontSee('Generate MDB')
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

    public function test_red_pdf_lines_are_page_scoped_repeatable_and_do_not_change_survey_rows_or_source(): void
    {
        $hash = hash('sha256', Storage::disk('local')->get($this->pdf->path));
        $before = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $payload = ['revision' => $this->batch->fresh()->revision, 'page' => 1, 'client_uuid' => (string) Str::uuid(),
            'start' => ['x' => .1, 'y' => .3], 'end' => ['x' => .9, 'y' => .3]];
        $route = route('mdb-workflow.entry.pdf.line', [$this->batch, $this->pdf]);
        $this->postJson($route, $payload)->assertOk()->assertJsonCount(1, 'pdf_lines');
        $this->postJson($route, $payload)->assertOk()->assertJsonCount(1, 'pdf_lines'); // Retried response: no duplicate mark.
        $this->assertDatabaseCount('mdb_workflow_pdf_lines', 1);
        $this->postJson($route, array_replace($payload, ['revision' => $this->batch->fresh()->revision, 'page' => 2, 'client_uuid' => (string) Str::uuid()]))
            ->assertOk()->assertJsonCount(2, 'pdf_lines');
        $this->getJson(route('mdb-workflow.entry.data', $this->batch))->assertOk()->assertJsonPath('pdf_lines.0.page', 1)->assertJsonPath('pdf_lines.1.page', 2);
        $line = PdfLine::first();
        $this->deleteJson(route('mdb-workflow.entry.pdf.line.delete', [$this->batch, $line]), ['revision' => $this->batch->fresh()->revision])
            ->assertOk()->assertJsonCount(1, 'pdf_lines');
        $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($this->pdf->path)));
        $this->assertSame($before['transformers'], app(SnapshotService::class)->snapshot($this->batch->fresh())['transformers']);
        $this->assertDatabaseCount('mdb_workflow_pdf_rows', 0);
        $this->assertDatabaseCount('mdb_workflow_entry_rows', 0);
    }

    public function test_red_line_validation_rejects_foreign_sources_stale_updates_and_locked_entry(): void
    {
        $payload = ['revision' => $this->batch->fresh()->revision, 'page' => 1, 'client_uuid' => (string) Str::uuid(),
            'start' => ['x' => .1, 'y' => .3], 'end' => ['x' => .9, 'y' => .3]];
        $route = route('mdb-workflow.entry.pdf.line', [$this->batch, $this->pdf]);
        foreach ([['page' => 15], ['end' => ['x' => 1.1, 'y' => .3]], ['end' => ['x' => .9, 'y' => .4]], ['end' => $payload['start']]] as $changes) {
            $this->postJson($route, array_replace($payload, $changes))->assertUnprocessable();
        }
        $this->postJson($route, array_replace($payload, ['revision' => -1]))->assertConflict();
        $this->postJson(route('mdb-workflow.entry.pdf.line', [$this->batch, $this->gpx]), $payload)->assertNotFound();
        $this->postJson($route, $payload)->assertOk();
        $other = $this->batch->replicate();
        $other->save();
        $this->postJson(route('mdb-workflow.entry.pdf.line', [$other, $this->pdf]), $payload)->assertNotFound();
        $this->deleteJson(route('mdb-workflow.entry.pdf.line.delete', [$other, PdfLine::first()]), ['revision' => $other->revision])->assertNotFound();
        $this->deleteJson(route('mdb-workflow.entry.pdf.line.delete', [$this->batch, PdfLine::first()]), ['revision' => -1])->assertConflict();
        $this->batch->update(['staged_workflow' => true, 'survey_status' => 'awaiting_verification']);
        $this->postJson($route, array_replace($payload, ['revision' => $this->batch->fresh()->revision, 'client_uuid' => (string) Str::uuid()]))->assertForbidden();
        $this->deleteJson(route('mdb-workflow.entry.pdf.line.delete', [$this->batch, PdfLine::first()]), ['revision' => $this->batch->fresh()->revision])->assertForbidden();
        $this->assertDatabaseCount('mdb_workflow_pdf_lines', 1);
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

    public function test_new_rows_default_to_feet_without_project_configuration(): void
    {
        $start = $this->save(['identity_version' => 2, 'pole_height' => 36, 'pole_height_unit' => null]);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'waypoint_reference' => '010', 'pole_height_unit' => null]);
        $issues = collect(app(EntryService::class)->issues($this->batch->fresh()));
        $this->assertCount(0, $issues->where('code', 'missing_entry_height_unit'));
        $this->assertSame('ft', $start->pole_height_unit);
        $this->assertSame('ft', $end->pole_height_unit);
        $this->assertCount(0, $issues->where('code', 'incomplete_entry_equipment'));
        foreach ([$start, $end] as $row) {
            $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]), $this->payload([
                'identity_version' => 2, 'client_uuid' => $row->client_uuid, 'designation' => $row->designation,
                'waypoint_reference' => $row->waypoint_reference, 'pole_height' => $row->pole_height, 'pole_height_unit' => 'ft',
            ]))->assertOk();
            $this->assertSame('ft', $row->fresh()->pole_height_unit);
        }
        $this->assertSame(36.0, $start->fresh()->pole_height);
        $this->assertNotContains('missing_entry_height_unit', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
        $this->assertNull(app(\App\Services\Mdb\SurveyWaypoint::class)->settings($this->batch->project_id)['pole_height_unit']);
    }

    public function test_custom_capacity_and_height_values_are_positive_and_survive_editing(): void
    {
        $header = $this->headerPayload(['capacity_kva' => 112.5]);
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $header)->assertOk();
        $this->assertEquals(112.5, (float) $this->transformer->fresh()->capacity_kva);
        $advanced = $this->get(route('mdb-workflow.advanced', $this->batch))->assertOk()->getContent();
        $this->assertStringContainsString('data-saved-capacity="112.5"', $advanced);
        $this->assertStringContainsString('112.5 kVA', $advanced);

        $row = $this->save(['pole_height' => 42.75]);
        $this->assertEquals(42.75, (float) $row->fresh()->pole_height);
        $this->assertSame('ft', $row->fresh()->pole_height_unit);
        $advanced = $this->get(route('mdb-workflow.advanced', $this->batch))->assertOk()->getContent();
        $this->assertStringContainsString('data-saved-height="42.75"', $advanced);

        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $row]), $this->payload([
            'revision' => $this->batch->fresh()->revision, 'client_uuid' => $row->client_uuid, 'save_uuid' => (string) Str::uuid(), 'pole_height' => 47.25,
        ]))->assertOk();
        $this->assertEquals(47.25, (float) $row->fresh()->pole_height);

        $invalid = $this->payload(['pole_height' => 0]);
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $invalid)->assertUnprocessable()->assertJsonValidationErrors('pole_height');
        $this->assertSame(1, $this->transformer->entryRows()->count());

        $invalidHeader = $this->headerPayload(['capacity_kva' => 0]);
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $invalidHeader)->assertUnprocessable()->assertJsonValidationErrors('capacity_kva');
        $this->assertEquals(112.5, (float) $this->transformer->fresh()->capacity_kva);
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
        $this->assertNull($row->gpx_source_id); // First S coordinates come from KMZ.
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

    public function test_continuation_inherits_omitted_components_and_manual_edits_clear_copy_provenance(): void
    {
        $this->entrySettings();
        $start = $this->save(['identity_version' => 2, 'row_date' => '2022-12-13']);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'waypoint_reference' => '002', 'group_number' => null, 'row_date' => null, 'inheritance' => ['group_date' => $start->id]]);
        $this->assertSame('01131222002', $end->composite_identifier);
        $this->assertSame('2022-12-13', $end->row_date->format('Y-m-d'));
        $this->assertSame($start->id, $end->inheritance['group_date']);
        $changed = $this->payload(['identity_version' => 2, 'pair_number' => 2, 'designation' => 'E', 'waypoint_reference' => '002',
            'group_number' => '02', 'row_date' => '2022-12-14', 'inheritance' => ['group_date' => $start->id]]);
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $end]), $changed)->assertOk();
        $end->refresh();
        $this->assertSame('02', $end->group_number);
        $this->assertSame('2022-12-14', $end->row_date->format('Y-m-d'));
        $this->assertArrayNotHasKey('group_date', $end->inheritance);

        $copied = $this->save(['pair_number' => 3, 'designation' => 'E', 'waypoint_reference' => '003', 'group_number' => '01',
            'row_date' => '2026-10-08', 'inheritance' => ['equipment' => $start->id]]);
        $changedHeight = $this->payload(['client_uuid' => $copied->client_uuid, 'pair_number' => 3, 'designation' => 'E', 'waypoint_reference' => '003',
            'inheritance' => ['equipment' => $start->id], 'pole_height' => 45]);
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $copied]), $changedHeight)->assertOk();
        $copied->refresh();
        $this->assertSame(45.0, $copied->pole_height);
        $this->assertArrayNotHasKey('equipment', $copied->inheritance);
    }

    public function test_repeated_gps_numbers_resolve_by_date_context_and_keep_distinct_network_nodes(): void
    {
        $this->entrySettings();
        $this->gpx->waypoints()->where('name', '001')->update(['recorded_at' => '2026-10-08 08:00:00']);
        $older = SourceFile::create(['batch_id' => $this->batch->id, 'kind' => 'gpx', 'path' => 'older.gpx', 'original_name' => 'older.gpx', 'sha256' => str_repeat('e', 64), 'bytes' => 9, 'mime' => 'application/gpx+xml', 'uploaded_by' => $this->admin->id, 'status' => 'ready']);
        $oldPoint = $older->waypoints()->create(['batch_id' => $this->batch->id, 'name' => '001', 'recorded_at' => '2022-12-13 08:00:00', 'latitude' => 30.1, 'longitude' => 71]);
        $this->transformer->update(['source_waypoint_id' => $oldPoint->id]);
        $start = $this->save(['identity_version' => 2, 'row_date' => '2022-12-13', 'gpx_source_id' => $older->id]);
        $end = $this->save(['identity_version' => 2, 'designation' => 'E', 'gpx_source_id' => null]);
        $this->assertSame($older->id, $start->gpx_source_id);
        $this->assertSame($this->gpx->id, $end->gpx_source_id);
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $points = array_column($snapshot['waypoints'], null, 'id');
        $network = app(SurveyWaypoint::class)->network($snapshot['transformers'][0], $points);
        $this->assertSame('first-s:'.$this->transformer->id.':'.$start->section_id, $network['sections'][0]['start_waypoint_id']);
        $this->assertSame('01131222001', $network['points'][$network['sections'][0]['start_waypoint_id']]['survey_identifier']);
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
        $row = $this->save(['identity_version' => 2, 'designation' => 'E', 'gpx_source_id' => null]);
        $this->assertNull($row->gpx_source_id);
        $this->assertContains('entry_ambiguous_waypoint', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
        $section = app(SnapshotService::class)->snapshot($this->batch->fresh())['transformers'][0]['sections'][0];
        $this->assertNull($section['end_waypoint_id']);
    }

    public function test_compact_markup_removes_routine_source_fields_and_separate_search_boxes(): void
    {
        $this->entrySettings();
        $html = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->getContent();
        $this->assertStringNotContainsString('Search conductor', $html);
        $this->assertStringNotContainsString('PDF row number / reference', $html);
        $this->assertStringContainsString('id="entry-height-unit-note"', $html);
        $this->assertStringContainsString('Paste Complete Waypoint', $html);
        $this->assertStringContainsString('Date (DDMMYY)', $html);
        $this->assertStringContainsString('S - Start', $html);
        $this->assertStringContainsString('E - End', $html);
        $this->assertStringNotContainsString('S ? Start', $html);
        $this->assertStringNotContainsString('E ? End', $html);
        $this->assertStringContainsString('id="sidebar-collapse-toggle"', $html);
        $this->assertStringContainsString('Hide menu', $html);
        $this->assertStringContainsString('entry-electrical-equipment-fields', $html);
        $this->assertStringContainsString('<legend>Conductor Detail</legend>', $html);
        $this->assertStringContainsString('<legend>Equipment Detail</legend>', $html);
        $this->assertStringContainsString('<input type="hidden" id="entry-phase">', $html);
        $this->assertStringContainsString('id="entry-height-choice"', $html);
        $this->assertStringContainsString('31 ft', $html);
        $this->assertStringContainsString('36 ft', $html);
        $this->assertStringContainsString('Other / Custom Value', $html);
        $this->assertSame(1, substr_count($html, 'class="entry-electrical-equipment"'));
        $this->assertSame(8, substr_count($html, 'data-compact-select'));
        $this->assertStringNotContainsString('entry-copy-endpoint', $html);
        $columns = ['name="designation"', 'name="group_number"', 'name="row_date"', 'name="waypoint_reference"', 'id="entry-phase"',
            'name="conductors[R]"', 'name="conductors[Y]"', 'name="conductors[B]"', 'name="conductors[N]"', 'name="equipment_type"', 'name="pole_class"', 'name="pole_height"',
            ...array_map(fn ($key) => 'name="consumers['.$key.']"', array_keys(EntryLookups::CONSUMERS)), 'name="intersection"', 'id="entry-pv-records"'];
        $positions = array_map(fn ($column) => strpos($html, $column), $columns);
        $ordered = $positions;
        sort($ordered);
        $this->assertSame($ordered, $positions);
        $this->get(route('mdb-workflow.config', $this->batch->project_id))->assertOk()->assertSee('Survey two-digit-year window starts at')->assertSee('Legacy survey source height unit');
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
        $this->assertNotContains('entry_missing_waypoint', $codes); // First S needs its first E, not an S GPX match.
        $this->assertContains('missing_operator_root', $codes);
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
        $review = $this->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('0000123')->assertSee('2.25')->assertSee('All S/E rows')
            ->assertSee('id="review-pdf-viewer"', false)->assertSee('id="review-pdf-height"', false)->assertSee('mdb-review-pdf.js');
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            file_put_contents(base_path('tmp/mdb-entry-qa/review.html'), $review->getContent());
        }
    }

    public function test_meter_only_pv_is_saved_projected_reviewed_and_included_in_snapshot(): void
    {
        $row = $this->save(['consumers' => ['pv' => 1], 'pv_details' => [
            ['reference' => null, 'service_load_kw' => 2.25, 'installed_capacity_kw' => 3.5,
                'meter_number' => '001-AB/09', 'remarks' => 'Meter only'],
        ]]);
        $this->assertSame('001-AB/09', $row->fresh()->pv_details[0]['meter_number']);
        $this->assertSame('001-AB/09', $row->pvRecords()->firstOrFail()->meter_number);
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $this->assertSame('001-AB/09', $snapshot['transformers'][0]['pv_records'][0]['meter_number']);
        $issues = app(\App\Services\Mdb\EntryService::class)->issues($this->batch->fresh());
        $this->assertNotContains('incomplete_entry_pv_details', array_column($issues, 'code'));
    }

    public function test_legacy_pv_snapshot_shape_remains_compatible_when_meter_number_is_null(): void
    {
        $this->save(['pv_details' => [['reference' => '00001', 'service_load_kw' => 2, 'installed_capacity_kw' => 3]]]);
        $service = app(SnapshotService::class);
        $snapshot = $service->snapshot($this->batch->fresh());
        $this->assertArrayNotHasKey('meter_number', $snapshot['transformers'][0]['pv_records'][0]);
        $revision = $service->capture($this->batch->fresh(), $this->batch->created_by);
        $this->assertSame($revision->sha256, $service->capture($this->batch->fresh(), $this->batch->created_by)->sha256);
    }

    public function test_continuation_and_header_corrections_keep_one_transformer_and_new_transformer_is_separate(): void
    {
        $row = $this->save();
        $this->postJson(route('mdb-workflow.pages.store', [$this->batch, $this->transformer]), ['revision' => $this->batch->fresh()->revision, 'source_pdf_id' => $this->pdf->id, 'page' => 2, 'role' => 'continuation', 'confirmed' => true])->assertOk();
        $this->assertSame('T-entry', $this->transformer->fresh()->code);
        $this->assertCount(2, $this->transformer->fresh()->header['pages']);
        $header = ['source_pdf_id' => $this->pdf->id, 'source_page' => 1, 'revision' => $this->batch->fresh()->revision, 'capacity_kva' => 125, 'header' => ['location' => 'Corrected location', 'mounting' => 'Double Pole', 'service_category' => 'General Duty']];
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $header)->assertOk();
        $this->assertSame('T-entry', $this->transformer->fresh()->code);
        $this->assertCount(2, $this->transformer->fresh()->header['pages']);
        $this->assertSame('000407', $this->transformer->fresh()->header['feeder_identifier']);
        $header['revision'] = $this->batch->fresh()->revision;
        $header['code'] = 'Unexpected replacement';
        $this->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]), $header)->assertOk();
        $this->assertSame('T-entry', $this->transformer->fresh()->code);
        $header['revision'] = $this->batch->fresh()->revision;
        $header['code'] = 'T-other';
        $header['registered_transformer_id'] = Transformer::where('feeder_id', $this->batch->feeder_id)->where('transformer_code', 'T-other')->value('id');
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $header)->assertOk();
        $other = $this->batch->transformers()->where('code', 'T-other')->firstOrFail();
        $this->assertSame(0, $other->entryRows()->count());
        $this->assertSame(1, $this->transformer->entryRows()->count());
        $this->get(route('mdb-workflow.show', [$this->batch, 'transformer_id' => $other->id]))->assertOk();
        $this->assertSame($this->transformer->id, $row->fresh()->transformer_id);
    }

    public function test_unique_gps_is_matched_automatically_despite_different_recording_date(): void
    {
        $point = $this->batch->waypoints()->where('name', '010')->firstOrFail();
        $point->update(['recorded_at' => '2026-10-08 07:46:12']);
        $this->save(['waypoint_reference' => '999', 'gpx_source_id' => null, 'row_date' => '2022-12-13']);
        $end = $this->save(['designation' => 'E', 'waypoint_reference' => '010', 'gpx_source_id' => null, 'row_date' => '2022-12-13']);
        $issues = collect(app(EntryService::class)->issues($this->batch->fresh()));
        $this->assertCount(0, $issues->where('code', 'entry_waypoint_source_mismatch'));
        $this->assertCount(0, $issues->where('code', 'entry_missing_waypoint'));
        $this->assertSame($point->id, $this->transformer->fresh()->source_waypoint_id);
        $this->assertSame($this->gpx->id, $end->fresh()->gpx_source_id);
        $this->assertSame([], app(EntryService::class)->issues($this->batch->fresh()));
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $end]), $this->payload([
            'client_uuid' => $end->client_uuid, 'designation' => 'E', 'waypoint_reference' => '010',
            'gpx_source_id' => $this->gpx->id, 'row_date' => '2022-12-13',
        ]))->assertOk();
        $this->assertSame($point->id, $this->transformer->fresh()->source_waypoint_id);
        $this->assertSame('2022-12-13', $end->fresh()->row_date->format('Y-m-d'));
        $this->assertSame('2026-10-08', $point->fresh()->recorded_at->format('Y-m-d'));
        $this->assertSame([], app(EntryService::class)->issues($this->batch->fresh()));
    }

    public function test_first_e_supplies_both_first_pair_coordinates_while_first_s_keeps_its_text_reference(): void
    {
        $this->get(route('mdb-workflow.show', $this->batch))->assertOk()
            ->assertDontSee('name="source_waypoint_id"', false)->assertDontSee('name="header[source_survey_identifier]"', false);
        $start = $this->save(['waypoint_reference' => '001', 'gpx_source_id' => null]);
        $this->assertNull($this->transformer->fresh()->source_waypoint_id);
        $this->assertSame('first_e_gpx', $start->fresh()->section->original_entry['start_coordinate_source']);
        $end = $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $expected = $this->batch->waypoints()->where('name', '010')->firstOrFail();
        $this->assertSame($expected->id, $this->transformer->fresh()->source_waypoint_id);
        $this->assertSame($end->id, $this->transformer->fresh()->header['location_entry_row_id']);
        $this->assertSame($end->composite_identifier, $this->transformer->fresh()->header['source_survey_identifier']);
        $this->assertSame('001', $start->fresh()->waypoint_reference);
        $this->assertNull($start->fresh()->gpx_source_id);
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $this->assertSame($expected->id, $snapshot['transformers'][0]['sections'][0]['start_waypoint_id']);
        $this->assertSame($expected->id, $snapshot['transformers'][0]['sections'][0]['end_waypoint_id']);
        $this->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('Same coordinates as first E')->assertSee('GPS 010 from GPX');
        $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $payload = $this->payload(['client_uuid' => $end->client_uuid, 'designation' => 'E', 'waypoint_reference' => '002']);
        $this->putJson(route('mdb-workflow.entry.rows.update', [$this->batch, $this->transformer, $end]), $payload)->assertOk();
        $this->assertSame($this->batch->waypoints()->where('name', '002')->value('id'), $this->transformer->fresh()->source_waypoint_id);
        $this->postJson(route('mdb-workflow.entry.rows.store', [$this->batch, $this->transformer]), $this->payload([
            'pair_number' => 2, 'designation' => 'E', 'waypoint_reference' => '001',
        ]))->assertOk();
        $this->deleteJson(route('mdb-workflow.entry.rows.destroy', [$this->batch, $this->transformer, $end]),
            ['revision' => $this->batch->fresh()->revision, 'confirmed' => true])->assertOk();
        $this->assertNull($this->transformer->fresh()->source_waypoint_id);
        $this->assertArrayNotHasKey('source_survey_identifier', $this->transformer->fresh()->header);
        $this->assertContains('missing_operator_root', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
        $this->save(['pair_number' => 2, 'designation' => 'S', 'waypoint_reference' => '998']);
        $this->assertContains('entry_missing_waypoint', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
    }

    public function test_first_pair_coordinate_rule_is_isolated_from_later_pairs_in_the_export_snapshot(): void
    {
        $this->save(['waypoint_reference' => '998', 'gpx_source_id' => null]);
        $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $this->save(['pair_number' => 2, 'designation' => 'S', 'waypoint_reference' => '001']);
        $this->save(['pair_number' => 2, 'designation' => 'E', 'waypoint_reference' => '002']);
        $original = $this->batch->waypoints()->get()->toArray();
        $snapshot = app(SnapshotService::class)->snapshot($this->batch->fresh());
        $sections = $snapshot['transformers'][0]['sections'];
        $this->assertSame($sections[0]['end_waypoint_id'], $sections[0]['start_waypoint_id']);
        $this->assertNotSame($sections[0]['end_waypoint_id'], $sections[1]['start_waypoint_id']);
        $this->assertNotSame($sections[0]['end_waypoint_id'], $sections[1]['end_waypoint_id']);
        $this->assertNotSame($sections[1]['start_waypoint_id'], $sections[1]['end_waypoint_id']);
        $this->assertSame($original, $this->batch->waypoints()->get()->toArray());
        $this->mock(ProjectionService::class)->shouldReceive('projectMany')->andReturnUsing(fn ($points) => array_map(fn ($p) => ['x' => $p['longitude'] * 1000, 'y' => $p['latitude'] * 1000], $points));
        $this->assertNotContains('missing_kmz_coordinates', array_column(app(NetworkValidator::class)->validate($snapshot)['errors'], 'code'));
        $this->gpx->waypoints()->where('name', '010')->update(['latitude' => 91]);
        $codes = array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code');
        $this->assertContains('invalid_first_e_coordinates', $codes);
        $this->gpx->waypoints()->where('name', '010')->update(['name' => '997']);
        $this->assertContains('missing_first_e_coordinates', array_column(app(EntryService::class)->issues($this->batch->fresh()), 'code'));
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

    public function test_submitting_one_transformer_as_json_keeps_same_pdf_open_for_another_transformer(): void
    {
        [$operator] = $this->workflowActors();
        $this->actingAs($operator);
        $this->save();
        $url = route('mdb-workflow.entry.review', $this->batch);
        $this->postJson($url, ['revision' => $this->batch->fresh()->revision, 'transformer_id' => $this->transformer->id, 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->save(['designation' => 'E', 'waypoint_reference' => '010']);
        $revision = $this->batch->fresh()->revision;
        $this->postJson($url, ['revision' => $revision, 'transformer_id' => $this->transformer->id, 'confirmed' => true])
            ->assertOk()->assertJsonPath('revision', $revision + 1)->assertJsonPath('survey_status', 'entry');
        $this->postJson($url, ['revision' => $revision, 'transformer_id' => $this->transformer->id, 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertTrue($this->transformer->fresh()->header['manually_verified']);
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload(['code' => 'NEXT-PDF-TRANSFORMER', 'manual_transformer' => true,
            'registered_transformer_id' => null, 'header' => ['inspector' => 'Surveyor', 'location' => 'Next transformer', 'service_category' => 'General Duty']]))
            ->assertOk();
        $next = $this->batch->transformers()->where('code', 'NEXT-PDF-TRANSFORMER')->firstOrFail();
        $this->assertEquals($this->pdf->id, $next->header['pdf_source_id']);
        $this->assertSame(2, $this->transformer->entryRows()->count());
        $this->assertSame(0, $next->entryRows()->count());
        $this->postJson(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])
            ->assertUnprocessable();
        $this->assertSame('entry', $this->batch->fresh()->survey_status);
    }

    public function test_finishing_transformer_entry_returns_dashboard_after_successful_verification_submission(): void
    {
        [$operator] = $this->workflowActors();
        $this->completeSurvey($operator);
        $this->postJson(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $this->batch->fresh()->revision, 'action' => 'submit'])
            ->assertOk()->assertJsonPath('redirect_url', route('mdb-workflow.index'));
        $this->assertSame('awaiting_verification', $this->batch->fresh()->survey_status);
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->postJson(route('mdb-workflow.entry.headers.store', $this->batch), $this->headerPayload())->assertForbidden();
    }

    public function test_empty_survey_submission_returns_to_pdf_entry_with_a_validation_message(): void
    {
        [$operator] = $this->workflowActors();
        $this->actingAs($operator);
        $this->transformer->delete();
        $revision = $this->batch->fresh()->revision;
        $entryUrl = route('mdb-workflow.show', $this->batch);

        // A feeder's registered transformers and uploaded files are not saved survey entries.
        $this->assertNotEmpty($this->batch->feeder->transformers);
        $this->assertNotEmpty($this->batch->sources);
        foreach ([$entryUrl, route('mdb-workflow.review', $this->batch)] as $previousUrl) {
            $this->from($previousUrl)->post(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $revision, 'action' => 'submit'])
                ->assertRedirect($entryUrl)->assertSessionHasErrors('transformer_id');
        }
        $this->get($entryUrl)->assertOk()->assertSee('Save at least one transformer header and its S/E rows');
        $this->assertSame('entry', $this->batch->fresh()->survey_status);
        $this->assertSame($revision, $this->batch->fresh()->revision);
        $this->assertNull($this->batch->fresh()->entry_completed_at);
        $this->assertSame(0, $this->batch->decisions()->count());
    }

    public function test_empty_survey_json_submission_returns_field_errors_without_changing_the_batch(): void
    {
        [$operator] = $this->workflowActors();
        $this->actingAs($operator);
        $this->transformer->delete();
        $revision = $this->batch->fresh()->revision;
        $this->postJson(route('mdb-workflow.survey.decision', $this->batch), ['revision' => $revision, 'action' => 'submit'])
            ->assertUnprocessable()->assertJsonValidationErrors('transformer_id');
        $this->assertSame('entry', $this->batch->fresh()->survey_status);
        $this->assertSame($revision, $this->batch->fresh()->revision);
        $this->assertSame(0, $this->batch->revisions()->count());
    }

    public function test_empty_survey_pages_disable_submission_and_link_to_pdf_entry(): void
    {
        [$operator] = $this->workflowActors();
        $this->actingAs($operator);
        $this->transformer->delete();
        $entry = $this->get(route('mdb-workflow.show', $this->batch))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button\b[^>]*id="entry-transformer-submit"[^>]*disabled[^>]*>Submit Transformer<\/button>/', $entry);
        $review = $this->get(route('mdb-workflow.review', $this->batch))->assertOk()->assertSee('No transformer has been saved in this batch yet.')
            ->assertSee(route('mdb-workflow.show', $this->batch), false)->getContent();
        $this->assertMatchesRegularExpression('/<button\b[^>]*disabled[^>]*>Submit for Verification<\/button>/', $review);
        if (getenv('MDB_ENTRY_QA_ARTIFACTS')) {
            file_put_contents(base_path('tmp/mdb-entry-qa/empty-operator-workflow.html'), $entry);
        }
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

    public function test_complete_staged_correction_approval_generation_and_reverification_with_genuine_mdb(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! app(WindowsExporter::class)->configured()) {
            $this->markTestSkipped('Windows DAO/ACE writer required.');
        }
        [$operator, $verifier, $creator] = $this->workflowActors();
        $this->actingAs($operator);
        // Synthetic, explicitly reviewed fixture only. These are never application defaults.
        foreach ([['S', '001', 1], ['E', '010', 1], ['S', '001', 2], ['E', '002', 2]] as [$designation,$reference,$pair]) {
            $this->save(['designation' => $designation, 'waypoint_reference' => $reference, 'pair_number' => $pair, 'consumers' => array_fill_keys(array_keys(EntryLookups::CONSUMERS), 0)]);
        }
        $this->transformer->refresh(); // Supply mapping connects to the automatically selected first E point.
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
        $decision = route('mdb-workflow.survey.decision', $this->batch);
        $review = fn () => $this->post(route('mdb-workflow.entry.review', $this->batch), ['revision' => $this->batch->fresh()->revision,
            'transformer_id' => $this->transformer->id, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $act = fn ($action, $remarks = null) => $this->post($decision, ['revision' => $this->batch->fresh()->revision, 'action' => $action, 'remarks' => $remarks])->assertRedirect()->assertSessionHasNoErrors();
        $review();
        $act('submit');
        $this->assertDatabaseCount('mdb_workflow_exports', 0);
        $this->actingAs($verifier);
        $act('return', 'Correct the transformer location against the PDF');
        $this->actingAs($operator)->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]),
            ['source_pdf_id' => $this->pdf->id, 'source_page' => 1, 'revision' => $this->batch->fresh()->revision, 'capacity_kva' => 100, 'source_waypoint_id' => $this->transformer->source_waypoint_id, 'header' => ['location' => 'Corrected location']])->assertOk();
        $review();
        $act('submit');
        $this->actingAs($verifier);
        $act('approve');
        $this->actingAs($creator)->post(route('mdb-workflow.generation.inputs.store', $this->batch),
            ['kind' => 'kmz', 'file' => $this->kmzUpload()])->assertRedirect()->assertSessionHasNoErrors();
        $this->app->instance(Exporter::class, app(WindowsExporter::class));
        $exports = app(ExportService::class);
        $job = $exports->request($this->batch->fresh(), $creator, $this->transformer->id);
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
        $this->assertSame($creator->id, $job->requested_by);
        $this->assertNotNull($job->generated_at);
        $this->assertSame('MDB Created', $this->batch->fresh()->workflow_label);
        $this->actingAs($creator)->get(route('mdb-workflow.exports.download', $job))->assertOk();
        $this->actingAs($operator)->post(route('mdb-workflow.reopen', $this->batch),
            ['revision' => $this->batch->fresh()->revision, 'remarks' => 'Updated location received'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($job->fresh()->superseded_at);
        $this->assertNull($this->batch->fresh()->approved_revision_id);
        $this->actingAs($creator)->postJson(route('mdb-workflow.exports.store', $this->batch), ['revision' => $this->batch->fresh()->revision])->assertForbidden();
        $this->get(route('mdb-workflow.exports.download', $job))->assertForbidden();
        $this->actingAs($operator)->putJson(route('mdb-workflow.entry.headers.update', [$this->batch, $this->transformer]),
            ['source_pdf_id' => $this->pdf->id, 'source_page' => 1, 'revision' => $this->batch->fresh()->revision, 'capacity_kva' => 100, 'source_waypoint_id' => $this->transformer->source_waypoint_id, 'header' => ['location' => 'Updated location']])->assertOk();
        $review();
        $act('submit');
        $this->actingAs($verifier);
        $act('approve');
        $newJob = $exports->request($this->batch->fresh(), $creator, $this->transformer->id);
        $this->assertNotSame($job->id, $newJob->id);
        $exports->run($newJob->id);
        $this->assertSame('generated', $newJob->fresh()->status);
        $this->assertNotNull($job->fresh()->superseded_at);
    }
}
