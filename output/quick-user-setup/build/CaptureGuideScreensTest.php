<?php

// Documentation-only capture harness. TestCase refuses any database except SQLite :memory:.
// All writes, roles, source files and users below are fictional test fixtures.
namespace Tests\Documentation;

use App\Enums\UserRole;
use App\Models\{Circle, Division, Feeder, FeederAssignment, GridStation, Project, SubDivision, SurveyTeam, User};
use App\Models\Mdb\{Configuration, EntryRow, SourceFile, SurveyBatch};
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Tests\TestCase;

class CaptureGuideScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_real_application_views_with_training_data(): void
    {
        Storage::fake('local'); Queue::fake(); Http::preventStrayRequests();
        config(['app.url' => 'http://guide.test', 'services.google_drive.enabled' => false]);
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $admin = User::factory()->create(['name' => 'Guide Administrator', 'email' => 'admin@example.com', 'role' => UserRole::SuperAdmin, 'status' => 'active']);
        $surveyor = User::factory()->create(['name' => 'Ali Surveyor', 'email' => 'ali.survey@example.com', 'role' => UserRole::Surveyor, 'status' => 'active']);
        $entry = User::factory()->create(['name' => 'Sara Entry', 'email' => 'sara.entry@example.com', 'role' => UserRole::SurveyDataEntryOperator, 'status' => 'active']);
        $verifier = User::factory()->create(['name' => 'Nadia Verifier', 'email' => 'nadia.verify@example.com', 'role' => UserRole::SurveyDataVerifier, 'status' => 'active']);
        $project = Project::create(['code' => 'TRAINING', 'name' => 'Training Project', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C01', 'name' => 'Training Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D01', 'name' => 'Training Division']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD01', 'name' => 'Training Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'GS01', 'name' => 'Training Substation']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id,
            'feeder_code' => '000407', 'feeder_name' => 'Training Feeder', 'status' => 'active', 'total_transformers' => 10]);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Survey Team 01', 'status' => 'active']);
        foreach ([$surveyor, $entry, $verifier] as $person) $team->members()->attach($person->id, ['is_leader' => false]);
        FeederAssignment::create(['feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'assigned_by' => $admin->id, 'start_date' => today()->subDay(), 'status' => 'active']);
        Configuration::create(['project_id' => $project->id, 'settings' => ['entry' => ['two_digit_year_start' => 2000, 'pole_height_unit' => 'ft']]]);
        $out = base_path('output/quick-user-setup/build');
        $capture = function (string $name, string $url) use ($out): void {
            $response = $this->get($url)->assertOk();
            file_put_contents($out.'/'.$name.'.html', $response->getContent());
        };
        $this->actingAs($admin);
        $capture('users', route('admin.users.index'));
        $capture('teams', route('admin.teams.index'));
        $capture('mobile-assignment', route('admin.assignments.index', ['user_id' => $surveyor->id, 'task_type' => 'mobile', 'survey_team_id' => $team->id, 'task_project' => $project->id]));
        $capture('create-batch', route('mdb-workflow.create'));
        $batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'survey_date' => '2026-10-31', 'created_by' => $admin->id,
            'staged_workflow' => true, 'survey_status' => 'entry']);
        $capture('entry-assignment', route('admin.assignments.index', ['user_id' => $entry->id, 'task_type' => 'entry', 'task_project' => $project->id]));
        $capture('workflow-entry-assign', route('team-work.workflow'));
        $batch->update(['entry_assigned_to' => $entry->id, 'entry_operator_id' => $entry->id, 'entry_actor_ids' => [$entry->id]]);
        $pdfBytes = $this->trainingPdf();
        file_put_contents($out.'/training-source.pdf', $pdfBytes);
        foreach (['pdf' => $pdfBytes, 'gpx' => '<gpx version="1.1" creator="Quick guide training"><wpt lat="34.001" lon="73.001"><name>01311026001</name><ele>100</ele></wpt><wpt lat="34.002" lon="73.002"><name>01311026002</name><ele>101</ele></wpt></gpx>'] as $kind => $bytes) {
            Storage::disk('local')->put('guide/training.'.$kind, $bytes);
            $source = SourceFile::create(['batch_id' => $batch->id, 'kind' => $kind, 'path' => 'guide/training.'.$kind, 'original_name' => 'Training-311026.'.$kind,
                'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'mime' => $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml', 'uploaded_by' => $admin->id,
                'status' => 'ready', 'metadata' => $kind === 'pdf' ? ['page_count' => 1] : []]);
            if ($kind === 'pdf') $pdf = $source; else $gpx = $source;
        }
        foreach ([1,2] as $index) $gpx->waypoints()->create(['batch_id' => $batch->id, 'name' => '0131102600'.$index, 'latitude' => 34.0 + $index / 1000, 'longitude' => 73.0 + $index / 1000, 'elevation' => 99 + $index]);
        $transformer = $batch->transformers()->create(['code' => 'TX-1001', 'capacity_kva' => 100, 'source_waypoint_id' => $gpx->waypoints()->first()->id,
            'header' => ['substation_name' => 'Training Substation', 'substation_identifier' => 'GS01', 'feeder_name' => 'Training Feeder', 'feeder_identifier' => '000407',
                'division' => 'Training Division', 'subdivision' => 'Training Subdivision', 'subdivision_code' => 'SD01', 'make' => 'Recorded make', 'inspector' => 'Field Inspector',
                'location' => 'Training location', 'survey_date' => '2026-10-31', 'team_group' => '01', 'mounting' => 'Double Pole', 'service_category' => 'General Duty',
                'pdf_source_id' => $pdf->id, 'pdf_start_page' => 1, 'pages' => [['source_pdf_id' => $pdf->id, 'page' => 1, 'role' => 'header', 'confirmed' => true]]]]);
        $section = $transformer->sections()->create(['section_key' => 'pair-1', 'start_waypoint_id' => $gpx->waypoints()->first()->id, 'end_waypoint_id' => $gpx->waypoints()->latest('id')->first()->id, 'phase_configuration' => 'ABC']);
        foreach (['S', 'E'] as $index => $designation) EntryRow::create(['transformer_id' => $transformer->id, 'section_id' => $section->id, 'client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'save_uuid' => (string) \Illuminate\Support\Str::uuid(), 'pair_number' => 1, 'designation' => $designation,
            'save_hash' => hash('sha256', 'guide-'.$designation), 'group_number' => '01', 'row_date' => '2026-10-31', 'waypoint_reference' => '0131102600'.($index+1), 'gpx_source_id' => $gpx->id, 'source_pdf_id' => $pdf->id, 'source_page' => 1,
            'conductors' => ['R' => 'AAC', 'Y' => 'AAC', 'B' => 'AAC', 'N' => 'AAC'], 'equipment_type' => 'S', 'pole_class' => '31', 'pole_height' => 31, 'pole_height_unit' => 'ft', 'consumers' => ['rs' => 2]]);
        $this->actingAs($entry);
        $capture('entry-screen', route('mdb-workflow.show', $batch));
        $capture('entry-submit', route('mdb-workflow.review', $batch));
        $batch->update(['survey_status' => 'awaiting_verification', 'status' => 'awaiting_verification', 'entry_completed_at' => now()]);
        $this->actingAs($admin);
        $capture('verification-assignment', route('admin.assignments.index', ['user_id' => $verifier->id, 'task_type' => 'verification', 'task_project' => $project->id]));
        $capture('workflow-verifier-assign', route('team-work.workflow'));
        $batch->update(['verification_assigned_to' => $verifier->id]);
        $capture('assignment-history', route('admin.assignments.index', ['user_id' => $verifier->id, 'task_type' => 'verification']));
        $capture('workflow-owners', route('team-work.workflow'));
        $this->actingAs($verifier);
        $capture('verification-review', route('mdb-workflow.review', $batch));
        $capture('verification-queue', route('mdb-workflow.index', ['queue' => 'survey_verification']));
        file_put_contents($out.'/fixture-ids.json', json_encode(['admin'=>$admin->id,'surveyor'=>$surveyor->id,'entry'=>$entry->id,'verifier'=>$verifier->id,'project'=>$project->id,'team'=>$team->id,'feeder'=>$feeder->id,'batch'=>$batch->id,'pdf'=>$pdf->id], JSON_PRETTY_PRINT));
    }

    private function trainingPdf(): string
    {
        $stream = "BT /F1 18 Tf 40 760 Td (TRAINING SURVEY SOURCE) Tj /F1 12 Tf 0 -30 Td (Training Feeder 000407 / Transformer TX-1001) Tj 0 -24 Td (Example only - use your own approved source PDF and GPX.) Tj 0 -40 Td (S  01  311026  001     E  01  311026  002) Tj ET";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $i=>$object) {$offsets[] = strlen($pdf); $pdf .= ($i+1)." 0 obj\n".$object."\nendobj\n";}
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
