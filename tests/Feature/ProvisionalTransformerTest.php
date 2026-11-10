<?php

namespace Tests\Feature;

use App\Models\{Circle, Division, Feeder, FeederAssignment, FieldSurvey, GridStation, Project, SubDivision, SurveyTeam, Transformer, TransformerCapacityObservation, TransformerKmzImport, User};
use App\Models\Mdb\{NetworkTransformer, SurveyBatch};
use App\Services\{TransformerKmzImportService, TransformerRegistryService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ProvisionalTransformerTest extends TestCase
{
    use RefreshDatabase;

    private Feeder $feeder;
    private User $admin;
    private User $collector;
    private User $verifier;
    private SurveyTeam $team;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->collector = User::factory()->create(['role' => 'mobile_survey_user']);
        $this->verifier = User::factory()->create(['role' => 'survey_data_verifier']);
        $project = Project::create(['code' => 'PROVISIONAL', 'name' => 'Provisional fixture', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id, 'feeder_code' => '012803', 'feeder_name' => 'City fixture', 'total_transformers' => 0, 'status' => 'active']);
        $this->team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Team 01', 'group_code' => '01', 'status' => 'active']);
        $this->team->members()->attach([$this->collector->id, $this->verifier->id]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);
    }

    private function file(bool $colocated = false): string
    {
        $points = '';
        foreach ([['A', 'DUPLICATE', 25, 73.1], ['B', 'DUPLICATE', 50, $colocated ? 73.1 : 73.2], ['C', 'ZERO', 0, 73.3], ['D', 'VALID', 100, 73.4]] as [$id, $code, $capacity, $lon]) {
            $rows = '';
            foreach (['Feeder' => 'City fixture', 'Equipment Type' => 'Transformer', 'Equipment Number' => $code, 'Equipment Size' => $capacity, 'GPS Waypoint Number' => $id] as $key => $value) $rows .= '<tr><td>'.$key.'</td><td>'.$value.'</td></tr>';
            $points .= '<Placemark id="'.$id.'"><description><![CDATA[<table>'.$rows.'</table>]]></description><Point><coordinates>'.$lon.',34.2,0</coordinates></Point></Placemark>';
        }
        $path = Storage::disk('local')->path('fixture.kmz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$points.'</Document></kml>');
        $zip->close();
        return $path;
    }

    private function import(string $file): TransformerKmzImport
    {
        $import = TransformerKmzImport::create(['feeder_id' => $this->feeder->id, 'imported_by' => $this->admin->id, 'file_name' => 'fixture.kmz', 'stored_path' => 'fixture.kmz']);
        return app(TransformerKmzImportService::class)->import($import, $file);
    }

    private function unknown(): Transformer
    {
        return Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'UNKNOWN', 'source_transformer_code' => 'UNKNOWN',
            'capacity_kva' => null, 'source_capacity_kva' => 0, 'capacity_verification_status' => 'pending_field',
            'source_identity_key' => hash('sha256', 'unknown'), 'latitude' => 34.2, 'longitude' => 73.1]);
    }

    private function photoObservation(Transformer $record): TransformerCapacityObservation
    {
        $this->actingAs($this->collector)->post(route('transformers.capacity.observe', $record), [
            'verification_revision' => $record->verification_revision, 'capacity_kva' => 200, 'evidence_note' => 'Nameplate read during field visit',
            'evidence_photo' => UploadedFile::fake()->image('nameplate.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        return $record->capacityObservations()->latest('id')->firstOrFail();
    }

    public function test_all_source_points_import_separately_unknown_capacity_is_null_and_reimport_is_idempotent(): void
    {
        $file = $this->file();
        $first = $this->import($file);
        $this->assertSame(4, $first->created_rows);
        $this->assertSame(3, $first->pending_rows);
        $this->assertSame(0, $first->skipped_rows);
        $this->assertSame(2, Transformer::where('code_verification_status', 'pending_client')->count());
        $unknown = Transformer::where('source_transformer_code', 'ZERO')->firstOrFail();
        $this->assertNull($unknown->capacity_kva);
        $this->assertSame('0.00', $unknown->source_capacity_kva);
        $before = Transformer::orderBy('id')->get()->map->only(['id', 'transformer_code', 'temporary_reference'])->all();
        $second = $this->import($file);
        $this->assertSame(0, $second->created_rows);
        $this->assertSame(4, $second->updated_rows);
        $this->assertSame($before, Transformer::orderBy('id')->get()->map->only(['id', 'transformer_code', 'temporary_reference'])->all());
        $this->assertSame(4, $this->feeder->fresh()->total_transformers);
    }

    public function test_client_confirmation_retains_internal_ids_source_evidence_field_work_and_survives_reimport(): void
    {
        $file = $this->file();$this->import($file);
        $record = Transformer::where('code_verification_status', 'pending_client')->firstOrFail();
        $oldCode = $record->transformer_code;
        $survey = FieldSurvey::create(['client_uuid' => (string) Str::uuid(), 'collected_by' => $this->collector->id, 'survey_team_id' => $this->team->id,
            'feeder_id' => $this->feeder->id, 'transformer_id' => $record->id, 'transformer_code' => $oldCode, 'survey_date' => today(), 'status' => 'draft',
            'rows' => [], 'header' => [], 'solar' => [], 'payload_hash' => str_repeat('a', 64), 'reference_snapshot' => ['transformer' => ['id' => $record->id, 'transformer_code' => $oldCode]]]);
        $before = $survey->fresh()->toArray();
        $this->actingAs($this->admin)->post(route('transformers.code.confirm', $record), ['verification_revision' => $record->verification_revision,
            'transformer_code' => 'CLIENT-CONFIRMED-01', 'reason' => 'Client confirmed this point', 'client_reference' => 'Client letter 10 November 2026'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('CLIENT-CONFIRMED-01', $record->fresh()->transformer_code);
        $this->assertSame('DUPLICATE', $record->fresh()->source_transformer_code);
        $this->assertContains($oldCode, $record->fresh()->code_aliases);
        $this->assertSame($before, $survey->fresh()->toArray());
        $this->import($file);
        $this->assertSame('CLIENT-CONFIRMED-01', $record->fresh()->transformer_code);
        $this->assertSame('confirmed', $record->fresh()->code_verification_status);
        $this->assertDatabaseCount('transformers', 4);
        $this->assertSame($record->id, app(TransformerRegistryService::class)->candidates($this->feeder->id, $oldCode)->first()->id);
        $this->assertDatabaseHas('transformer_registry_changes', ['transformer_id' => $record->id, 'kind' => 'client_code_confirmation', 'changed_by' => $this->admin->id]);
    }

    public function test_distinct_duplicate_coded_features_remain_separate_even_when_their_coordinates_are_identical(): void
    {
        $path = $this->file(true);
        $first = $this->import($path);
        $this->assertSame(4, $first->created_rows);
        $this->assertSame(2, Transformer::where('source_transformer_code', 'DUPLICATE')->count());
        $ids = Transformer::where('source_transformer_code', 'DUPLICATE')->pluck('id')->all();
        $this->import($path);
        $this->assertSame($ids, Transformer::where('source_transformer_code', 'DUPLICATE')->pluck('id')->all());
    }

    public function test_capacity_requires_independent_verification_and_survives_zero_capacity_reimport(): void
    {
        $file = $this->file();$this->import($file);
        $record = Transformer::where('source_transformer_code', 'ZERO')->firstOrFail();
        $observation = $this->photoObservation($record);
        $this->assertNull($record->fresh()->capacity_kva);
        $review = route('transformers.capacity.review', [$record, $observation]);
        $body = ['verification_revision' => $record->verification_revision, 'decision' => 'approved', 'review_note' => 'Checked nameplate and field evidence'];
        $this->actingAs($this->collector)->post($review, $body)->assertForbidden();
        $this->actingAs($this->verifier)->post($review, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('200.00', $record->fresh()->capacity_kva);
        $this->assertSame('confirmed', $record->fresh()->capacity_verification_status);
        $this->assertSame($this->verifier->id, $record->fresh()->capacity_verified_by);
        $this->import($file);
        $this->assertSame('200.00', $record->fresh()->capacity_kva);
        $this->assertSame('0.00', $record->fresh()->source_capacity_kva);
    }

    public function test_code_and_capacity_can_be_confirmed_independently_without_discarding_the_field_observation(): void
    {
        $record = $this->unknown();$record->update(['code_verification_status' => 'pending_client']);
        $observation = $this->photoObservation($record->fresh());
        $this->actingAs($this->admin)->post(route('transformers.code.confirm', $record), ['verification_revision' => 1,
            'transformer_code' => 'CLIENT-NEW', 'reason' => 'Client confirmation received', 'client_reference' => 'Confirmed client letter #10'])->assertSessionHasNoErrors();
        $this->actingAs($this->verifier)->post(route('transformers.capacity.review', [$record, $observation]), ['verification_revision' => $record->fresh()->verification_revision,
            'decision' => 'approved', 'review_note' => 'Checked the original nameplate photograph'])->assertSessionHasNoErrors();
        $this->assertFalse($record->fresh()->hasPendingVerification());
    }

    public function test_unauthorized_codes_duplicate_codes_stale_updates_and_missing_evidence_are_rejected(): void
    {
        $record = $this->unknown()->fresh();
        $other = $record->replicate(['transformer_code', 'source_identity_key']);$other->transformer_code = 'TAKEN';$other->source_identity_key = hash('sha256', 'other');$other->save();
        $body = ['verification_revision' => 1, 'transformer_code' => 'TAKEN', 'reason' => 'Confirmed official code', 'client_reference' => 'Client letter #1234'];
        $this->actingAs($this->collector)->post(route('transformers.code.confirm', $record), $body)->assertForbidden();
        $this->actingAs($this->admin)->post(route('transformers.code.confirm', $record), $body)->assertSessionHasErrors('transformer_code');
        $body['transformer_code'] = 'AVAILABLE';$body['verification_revision'] = 0;
        $this->post(route('transformers.code.confirm', $record), $body)->assertConflict();
        $observation = $this->photoObservation($record);
        Storage::disk('local')->delete($observation->evidence_path);
        $this->actingAs($this->verifier)->post(route('transformers.capacity.review', [$record, $observation]), ['verification_revision' => 1,
            'decision' => 'approved', 'review_note' => 'Reviewed the capacity evidence'])->assertUnprocessable();
        $this->assertNull($record->fresh()->capacity_kva);
        $outsider = User::factory()->create(['role' => 'mobile_survey_user']);
        $this->actingAs($outsider)->get(route('transformers.show', $record))->assertNotFound();
        $this->get(route('transformers.capacity.evidence', [$record, $observation]))->assertNotFound();
    }

    public function test_selected_resolved_transformer_can_proceed_while_another_transformer_is_pending(): void
    {
        $unknown = $this->unknown()->fresh();
        $known = Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'KNOWN', 'capacity_kva' => 50, 'latitude' => 34.2, 'longitude' => 73.2]);
        $batch = SurveyBatch::create(['project_id' => $this->feeder->project_id, 'feeder_id' => $this->feeder->id, 'survey_date' => today(), 'created_by' => $this->admin->id]);
        $bad = $batch->transformers()->create(['code' => $unknown->transformer_code, 'header' => ['registered_transformer_id' => $unknown->id]]);
        $good = $batch->transformers()->create(['code' => $known->transformer_code, 'capacity_kva' => 50, 'header' => ['registered_transformer_id' => $known->id]]);
        app(TransformerRegistryService::class)->assertForBatch($batch, $good->id);
        $this->addToAssertionCount(1);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field verification');
        app(TransformerRegistryService::class)->assertForBatch($batch, $bad->id);
    }

    public function test_standalone_builder_also_blocks_pending_registered_references(): void
    {
        $record = $this->unknown()->fresh();
        $workspace = new \App\Models\TransformerMdbProject(['feeder_id' => $this->feeder->id, 'transformer_code' => $record->transformer_code,
            'header' => ['capacity_kva' => 50], 'rows' => [], 'export_settings' => []]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field verification');
        app(\App\Services\TransformerMdbNetworkService::class)->build($workspace);
    }

    public function test_registry_correction_reopens_linked_pdf_work_but_retains_transformer_rows_and_original_header(): void
    {
        $record = $this->unknown()->fresh();
        $batch = SurveyBatch::create(['project_id' => $this->feeder->project_id, 'feeder_id' => $this->feeder->id, 'survey_date' => today(),
            'created_by' => $this->admin->id, 'staged_workflow' => true, 'survey_status' => 'approved', 'status' => 'approved']);
        $work = $batch->transformers()->create(['code' => $record->transformer_code, 'capacity_kva' => 25,
            'header' => ['registered_transformer_id' => $record->id, 'manually_verified' => true], 'original_header' => ['code' => $record->transformer_code, 'capacity_kva' => 25]]);
        $id = $work->id;$original = $work->original_header;
        app(TransformerRegistryService::class)->confirmCode($this->admin, $record, ['verification_revision' => 1, 'transformer_code' => 'CLIENT-FINAL', 'reason' => 'Client official identity confirmed', 'client_reference' => 'Client letter #123']);
        $this->assertSame($id, $work->fresh()->id);
        $this->assertSame($original, $work->fresh()->original_header);
        $this->assertSame('CLIENT-FINAL', $work->fresh()->code);
        $this->assertSame(25.0, $work->fresh()->capacity_kva);
        $this->assertSame('returned', $batch->fresh()->survey_status);
    }

    public function test_reference_pages_show_pending_states_and_role_specific_correction_controls(): void
    {
        $record = $this->unknown()->fresh();
        $this->actingAs($this->collector)->get(route('transformers.show', $record))->assertOk()->assertSee('Record capacity from field visit')->assertDontSee('Save client confirmation');
        $this->actingAs($this->admin)->get(route('transformers.show', $record))->assertOk()->assertSee('Save client confirmation')->assertSee('Unknown capacity');
        $this->get(route('transformers.index', ['verification' => 'pending_field']))->assertOk()->assertSee('UNKNOWN');
    }

    public function test_admin_transformer_table_renders_unknown_capacities_and_filters_known_and_pending_ratings(): void
    {
        $unknown = $this->unknown()->fresh();
        $known = Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'KNOWN-50', 'capacity_kva' => 50, 'latitude' => 34.2, 'longitude' => 73.2]);
        $this->actingAs($this->admin);
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->get('/admin/transformers')->assertOk()->assertSee('Unknown');
        \Livewire\Livewire::test(\App\Filament\Resources\Transformers\Pages\ListTransformers::class)
            ->assertCanSeeTableRecords([$unknown, $known])
            ->filterTable('capacity_kva', '50')->assertCanSeeTableRecords([$known])->assertCanNotSeeTableRecords([$unknown]);
        \Livewire\Livewire::test(\App\Filament\Resources\Transformers\Pages\ListTransformers::class)
            ->filterTable('capacity_verification_status', 'pending_field')->assertCanSeeTableRecords([$unknown])->assertCanNotSeeTableRecords([$known]);
    }

    public function test_supplied_client_sample_registers_every_point_and_reimports_without_duplicate_records(): void
    {
        $path = getenv('TRANSFORMER_KMZ_SAMPLE');
        if (! $path || ! is_file($path)) $this->markTestSkipped('Set TRANSFORMER_KMZ_SAMPLE to inspect the client sample in an isolated SQLite test.');
        $first = $this->import($path);
        $this->assertSame(161, $first->created_rows);
        $this->assertSame(6, $first->pending_rows);
        $this->assertSame(0, $first->skipped_rows);
        $this->assertSame(5, Transformer::where('code_verification_status', 'pending_client')->count());
        $this->assertSame(1, Transformer::where('capacity_verification_status', 'pending_field')->count());
        $ids = Transformer::orderBy('id')->pluck('source_identity_key', 'id')->all();
        $second = $this->import($path);
        $this->assertSame(0, $second->created_rows);
        $this->assertSame(161, $second->updated_rows);
        $this->assertSame($ids, Transformer::orderBy('id')->pluck('source_identity_key', 'id')->all());
    }
}
