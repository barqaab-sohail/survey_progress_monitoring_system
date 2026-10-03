<?php

namespace Tests\Feature;

use App\Models\HtDataImport;
use App\Models\Project;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\HazecoHtDataImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HazecoHtDataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_and_then_updates_hazeco_ht_feeder_data(): void
    {
        $project = Project::create([
            'code' => 'HAZECO-TDL',
            'name' => 'HAZECO T&D Losses',
            'timezone' => 'Asia/Karachi',
            'processing_required' => true,
            'status' => 'active',
        ]);
        $user = User::factory()->create(['role' => 'super_admin']);
        $path = tempnam(sys_get_temp_dir(), 'hazeco-');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(HazecoHtDataImportService::SHEET);
        $sheet->fromArray([
            ['Sr', 'Feeder Name', 'Feeder Code', 'Load (kW)', 'No of Consumers', 'Grid Code', 'Grid Name', 'Circle', 'Circle Code', 'Division', 'Division Code', 'Subdivision', 'Subdivision Code', 'Nature'],
            [1, 'EXPRESS-TOWN-1', '5901', 25108, 11843, 'G-01', '132kV Haripur', 'Hazara', '271', 'City Haripur', '2711', 'City Haripur', '27111', 'Mix'],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $first = HtDataImport::create(['project_id' => $project->id, 'imported_by' => $user->id, 'file_name' => basename($path)]);
            $result = app(HazecoHtDataImportService::class)->import($first, $path);
            $this->assertSame('completed', $result->status);
            $this->assertSame(1, $result->created_rows);
            $this->assertDatabaseHas('feeders', [
                'feeder_code' => '5901',
                'feeder_name' => 'EXPRESS-TOWN-1',
                'number_of_consumers' => 11843,
                'total_transformers' => 0,
                'baseline_pending' => true,
            ]);
            $this->assertSame('BASELINE PENDING', app(DashboardService::class)->feederProgress()->first()->progress_status);

            $second = HtDataImport::create(['project_id' => $project->id, 'imported_by' => $user->id, 'file_name' => basename($path)]);
            $updated = app(HazecoHtDataImportService::class)->import($second, $path);
            $this->assertSame(0, $updated->created_rows);
            $this->assertSame(1, $updated->updated_rows);
            $this->assertDatabaseCount('feeders', 1);
        } finally {
            @unlink($path);
        }
    }

    public function test_only_authorized_active_users_can_access_filament(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $viewer = User::factory()->create(['role' => 'management_viewer', 'status' => 'active']);

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($viewer)->get('/admin')->assertForbidden();
    }
}
