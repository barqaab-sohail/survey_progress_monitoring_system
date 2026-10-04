<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\Transformer;
use App\Models\TransformerKmzImport;
use App\Models\User;
use App\Services\TransformerKmzImportService;
use Database\Seeders\ShieldPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class TransformerKmzImportTest extends TestCase
{
    use RefreshDatabase;

    private Feeder $feeder;

    private User $admin;

    private User $surveyor;

    private User $mdbUser;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::create(['name' => 'BARQAAB', 'type' => 'internal', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyor = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->mdbUser = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::MdbTeamUser]);
        $project = Project::create(['code' => 'P1', 'name' => 'Project', 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $this->feeder = Feeder::create([
            'project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id,
            'feeder_code' => 'F-01', 'feeder_name' => 'Excel Feeder', 'total_transformers' => 0,
            'baseline_pending' => true, 'processing_required' => true, 'status' => 'active',
        ]);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST1', 'name' => 'Survey Team', 'status' => 'active']);
        $team->members()->attach($this->surveyor->id, ['is_leader' => true]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $team->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_kmz_import_selects_only_valid_transformer_points_and_synchronizes_feeder(): void
    {
        $file = $this->kmz([
            $this->point('pole-1', ['Feeder' => 'SOURCE_A', 'Equipment Type' => 'Primary Pole'], '73.0000,34.0000,0'),
            $this->point('tx-1', $this->transformerFields('T-001', '25', '73.1000', '34.2000') + ['Residential Consumer' => '12'], '73.1000,34.2000,0'),
            $this->point('tx-2', $this->transformerFields('T-002', '50 kVA', '73.2000', '34.3000') + ['Equipment Make' => 'PEL'], '73.2000,34.3000,0'),
            '<Placemark id="line-1"><LineString><coordinates>73,34 74,35</coordinates></LineString></Placemark>',
        ]);
        $import = TransformerKmzImport::create([
            'feeder_id' => $this->feeder->id, 'imported_by' => $this->admin->id,
            'file_name' => 'source.kmz', 'stored_path' => 'source.kmz', 'status' => 'pending',
        ]);

        $result = app(TransformerKmzImportService::class)->import($import, $file);

        $this->assertSame('completed', $result->status);
        $this->assertSame(4, $result->total_placemarks);
        $this->assertSame(3, $result->point_placemarks);
        $this->assertSame(2, $result->transformer_count);
        $this->assertSame('SOURCE_A', $result->source_feeder_name);
        $this->assertDatabaseCount('transformers', 2);
        $this->assertDatabaseHas('transformers', [
            'feeder_id' => $this->feeder->id, 'transformer_code' => 'T-002',
            'capacity_kva' => 50, 'equipment_make' => 'PEL',
        ]);
        $this->assertSame(12, Transformer::where('transformer_code', 'T-001')->value('residential_total'));
        $this->feeder->refresh();
        $this->assertSame(2, $this->feeder->total_transformers);
        $this->assertFalse($this->feeder->baseline_pending);

        $replacement = $this->kmz([
            $this->point('tx-1-new', $this->transformerFields('T-001', '100', '73.1000', '34.2000'), '73.1000,34.2000,0'),
        ]);
        $secondImport = TransformerKmzImport::create([
            'feeder_id' => $this->feeder->id, 'imported_by' => $this->admin->id,
            'file_name' => 'replacement.kmz', 'stored_path' => 'replacement.kmz', 'status' => 'pending',
        ]);
        $secondResult = app(TransformerKmzImportService::class)->import($secondImport, $replacement);

        $this->assertSame(1, $secondResult->transformer_count);
        $this->assertSame(1, $secondResult->updated_rows);
        $this->assertSame(1, $secondResult->removed_rows);
        $this->assertDatabaseCount('transformers', 1);
        $this->assertDatabaseHas('transformers', ['transformer_code' => 'T-001', 'capacity_kva' => 100]);
    }

    public function test_invalid_kmz_is_rejected_without_changing_existing_transformers(): void
    {
        Transformer::create($this->databaseTransformer('EXISTING-1'));
        $invalid = $this->kmz([
            $this->point('bad-1', $this->transformerFields('DUPLICATE', '25', '73.1000', '34.2000'), '73.1000,34.2000,0'),
            $this->point('bad-2', $this->transformerFields('DUPLICATE', '50', '73.2000', '34.3000'), '73.2000,34.3000,0'),
        ]);
        $import = TransformerKmzImport::create([
            'feeder_id' => $this->feeder->id, 'imported_by' => $this->admin->id,
            'file_name' => 'invalid.kmz', 'stored_path' => 'invalid.kmz', 'status' => 'pending',
        ]);

        try {
            app(TransformerKmzImportService::class)->import($import, $invalid);
            $this->fail('Duplicate transformer codes should reject the KMZ.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate transformer number', $exception->getMessage());
        }

        $this->assertSame('failed', $import->fresh()->status);
        $this->assertDatabaseCount('transformers', 1);
        $this->assertDatabaseHas('transformers', ['transformer_code' => 'EXISTING-1']);
    }

    public function test_survey_scope_and_mdb_access_are_enforced_for_transformer_reference(): void
    {
        $assigned = Transformer::create($this->databaseTransformer('ASSIGNED-TX'));
        $otherFeeder = $this->feeder->replicate(['feeder_code', 'feeder_name']);
        $otherFeeder->feeder_code = 'F-99';
        $otherFeeder->feeder_name = 'Other Feeder';
        $otherFeeder->save();
        $other = Transformer::create($this->databaseTransformer('PRIVATE-TX', $otherFeeder));

        $this->actingAs($this->surveyor)->get('/transformer-gis')
            ->assertOk()->assertSee('ASSIGNED-TX')->assertDontSee('PRIVATE-TX');
        $this->get('/transformer-gis/'.$assigned->id)->assertOk()->assertSee('ASSIGNED-TX');
        $this->get('/transformer-gis/'.$other->id)->assertNotFound();

        $this->actingAs($this->mdbUser)->get('/transformer-gis')
            ->assertOk()->assertSee('ASSIGNED-TX')->assertSee('PRIVATE-TX');
        $this->get('/transformer-gis/'.$other->id)->assertOk()->assertSee('PRIVATE-TX');

        $viewer = User::factory()->create(['organization_id' => $this->admin->organization_id, 'role' => UserRole::ManagementViewer]);
        $this->actingAs($viewer)->get('/transformer-gis')->assertForbidden();
    }

    public function test_authorized_admin_can_open_filament_transformer_resources(): void
    {
        $this->seed(ShieldPermissionSeeder::class);

        $this->actingAs($this->admin)->get('/admin/transformer-kmz-imports')
            ->assertOk()->assertSee('Bulk import KMZ files');
        $this->get('/admin/transformers')->assertOk()->assertSee('Transformers');
    }

    /** @param array<int, string> $placemarks */
    private function kmz(array $placemarks): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hazeco-kmz-');
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $kml = '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2" xsi:schemaLocation="ignored"><Document>'.implode('', $placemarks).'</Document></kml>';
        $zip->addFromString('doc.kml', $kml);
        $zip->close();

        return $path;
    }

    /** @param array<string, string> $fields */
    private function point(string $id, array $fields, string $coordinates): string
    {
        $rows = '';
        foreach ($fields as $label => $value) {
            $rows .= '<tr><td>'.htmlspecialchars($label).'</td><td>'.htmlspecialchars($value).'</td></tr>';
        }

        return '<Placemark id="'.$id.'"><description><![CDATA[<table>'.$rows.'</table>]]></description><Point><coordinates>'.$coordinates.'</coordinates></Point></Placemark>';
    }

    /** @return array<string, string> */
    private function transformerFields(string $code, string $capacity, string $x, string $y): array
    {
        return [
            'Substation' => 'SOURCE_SUB', 'Feeder' => 'SOURCE_A',
            'GPS Waypoint Number' => 'WP-'.$code, 'Equipment Type' => 'Transformer',
            'Equipment Number' => $code, 'Equipment Size' => $capacity,
            'Equipment Status' => 'Connected', 'Equipment Mounting' => 'Pole',
            'POINT_X' => $x, 'POINT_Y' => $y,
        ];
    }

    /** @return array<string, mixed> */
    private function databaseTransformer(string $code, ?Feeder $feeder = null): array
    {
        return [
            'feeder_id' => ($feeder ?? $this->feeder)->id,
            'transformer_code' => $code,
            'source_feeder_name' => 'SOURCE_A',
            'capacity_kva' => 25,
            'longitude' => 73.1,
            'latitude' => 34.2,
        ];
    }
}
