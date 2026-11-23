<?php

namespace Tests\Feature;

use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\GridStation;
use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\ServiceCableExcelExport;
use App\Services\ServiceCableRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceCableExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Feeder $feeder;
    private Feeder $other;
    private SurveyTeam $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $project = Project::create(['code' => 'FIELD', 'name' => 'Field', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G', 'name' => 'Grid']);
        $attributes = ['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_name' => 'Selected feeder', 'total_transformers' => 0, 'status' => 'active'];
        $this->feeder = Feeder::create($attributes + ['feeder_code' => '0012']);
        $this->other = Feeder::create(array_replace($attributes, ['feeder_code' => '9999', 'feeder_name' => 'Other feeder']));
        $this->team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST', 'name' => 'Team', 'status' => 'active']);
    }

    private function survey(Feeder $feeder, string $transformer, array $cables, string $status = 'draft'): FieldSurvey
    {
        return FieldSurvey::forceCreate(['client_uuid' => (string) Str::uuid(), 'collected_by' => $this->admin->id,
            'survey_team_id' => $this->team->id, 'feeder_id' => $feeder->id, 'transformer_code' => $transformer,
            'survey_date' => today(), 'status' => $status, 'revision' => 1, 'payload_hash' => str_repeat('a', 64),
            'header' => ['capacity_kva' => 50], 'rows' => [['row_id' => 'pole', 'gps_waypoint' => '001', 'se' => 'E']],
            'solar' => [['unrelated' => 'DO NOT EXPORT']], 'reference_snapshot' => [], 'service_cables' => $cables]);
    }

    private function cables(int $index): array
    {
        $route = app(ServiceCableRoute::class)->normalize(['route_points' => [
            ['latitude' => 34, 'longitude' => 73, 'waypoint' => 'Pole'],
            ['latitude' => 34.001, 'longitude' => 73, 'waypoint' => 'Street bend'],
            ['latitude' => 34.001, 'longitude' => 73.001, 'waypoint' => 'Meter'],
        ]]);
        $base = ['pole_row_id' => 'pole', 'category' => 'rs', 'consumer_number' => 1, 'gps_waypoint' => '002',
            'phase' => 'R', 'meter_number' => '000'.$index, 'consumer_reference' => '00012-'.$index];
        return [
            $route + $base + ['cable_id' => (string) Str::uuid(), 'measured_length_m' => 999, 'remarks' => '=1+1'],
            array_replace($base, ['cable_id' => (string) Str::uuid(), 'phase' => 'RYB', 'measured_length_m' => 20.123 + $index]),
            array_replace($base, ['cable_id' => (string) Str::uuid(), 'measured_length_m' => null, 'phase' => null]),
        ];
    }

    public static function transformerCounts(): array
    {
        return ['two' => [2], 'three' => [3], 'five' => [5], 'beyond template capacity' => [48]];
    }

    #[DataProvider('transformerCounts')]
    public function test_every_saved_cable_exports_at_any_survey_stage_without_other_feeders_or_estimates(int $count): void
    {
        $expected = [];
        for ($i = 1; $i <= $count; $i++) {
            $cables = $this->cables($i);
            $this->survey($this->feeder, sprintf('TR-%03d', $i), $cables, $i % 2 ? 'draft' : 'submitted');
            foreach ($cables as $cable) $expected[$cable['cable_id']] = ! empty($cable['route_points']) ? $cable['route_length_m'] : $cable['measured_length_m'];
        }
        $this->survey($this->other, 'OTHER-FEEDER-TR', $this->cables(99));
        $deleted = $this->survey($this->feeder, 'DELETED-TR', $this->cables(98));
        $deleted->delete();
        $this->survey($this->feeder, 'UNRELATED-LT', []);

        $export = app(ServiceCableExcelExport::class);
        $records = $export->records($this->feeder);
        $this->assertCount($count * 3, $records);
        $this->actingAs($this->admin)->get(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]))
            ->assertOk()->assertSee('Capacity (kVA)')->assertViewHas('records', fn ($rows) => $rows->count() === $count * 3);
        $response = $this->get(route('service-cable-exports.download', [
            'feeder' => $this->feeder->id, 'transformers' => $records->pluck('transformer_key')->unique()->values()->all(),
        ]));
        $response->assertDownload()->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        try {
            $details = $book->getSheetByName('Service Cable Records');
            $this->assertSame($count * 3 + 1, $details->getHighestDataRow());
            $sum = 0;
            for ($row = 2; $row <= $count * 3 + 1; $row++) {
                $this->assertSame('0012', $details->getCell('B'.$row)->getValue());
                $id = explode(':', $details->getCell('A'.$row)->getValue())[2];
                $this->assertArrayHasKey($id, $expected);
                $value = $details->getCell('Q'.$row)->getValue();
                if ($expected[$id] === null) $this->assertNull($value);
                else $this->assertEqualsWithDelta($expected[$id], $value, 0.0000001);
                $sum += $value ?? 0;
                $this->assertNotSame(DataType::TYPE_FORMULA, $details->getCell('U'.$row)->getDataType());
            }
            $this->assertSame($count * 3 + 1, $book->getSheetByName('Route Points')->getHighestDataRow());
            $length = $book->getSheetByName('Length');
            $this->assertSame('Transformer Id', $length->getCell('B1')->getValue());
            $this->assertSame('Service Cable Length (Meter)', $length->getCell('D1')->getValue());
            $this->assertEqualsWithDelta($sum, $length->getCell('D'.(49 + max(0, $count - 46)))->getValue(), 0.0000001);
            $this->assertArrayHasKey('D1:F1', $length->getMergeCells());
            $consumer = $book->getSheetByName('Consumer Sheet');
            $template = IOFactory::load(resource_path('templates/lt-network-gis-output-5oct2026.xlsx'));
            $this->assertSame($template->getSheet(0)->getStyle('A8')->getHashCode(), $consumer->getStyle('A8')->getHashCode());
            $template->disconnectWorksheets();
            $this->assertNull($consumer->getCell('H8')->getValue());
            $this->assertNull($consumer->getCell('I8')->getValue());
            $this->assertNull($consumer->getCell('J8')->getValue());
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_pdf_saved_cables_are_included_without_batch_approval(): void
    {
        $this->survey($this->feeder, 'MOBILE-TR', $this->cables(1));
        $batch = SurveyBatch::create(['project_id' => $this->feeder->project_id, 'feeder_id' => $this->feeder->id,
            'survey_team_id' => $this->team->id, 'survey_date' => today(), 'created_by' => $this->admin->id, 'status' => 'draft']);
        $transformer = NetworkTransformer::create(['batch_id' => $batch->id, 'code' => 'PDF-TR']);
        $pole = EntryRow::create(['transformer_id' => $transformer->id, 'client_uuid' => Str::uuid(), 'save_uuid' => Str::uuid(),
            'save_hash' => str_repeat('b', 64), 'pair_number' => 1, 'designation' => 'E', 'conductors' => [], 'consumers' => []]);
        $transformer->serviceCables()->create(['entry_row_id' => $pole->id, 'category' => 'rs', 'consumer_number' => 1,
            'group_number' => '01', 'row_date' => today(), 'waypoint_reference' => '002', 'composite_identifier' => '01221126002',
            'source_page' => 1, 'measured_length_m' => 12.987, 'phase' => 'Y', 'consumer_reference' => '00101']);
        $records = app(ServiceCableExcelExport::class)->records($this->feeder);
        $this->assertCount(4, $records);
        $this->assertSame(12.987, $records->firstWhere('source', 'PDF survey')['length_m']);
        $this->assertSame('00101', $records->firstWhere('source', 'PDF survey')['consumer_reference']);

        $source = $batch->sources()->create(['kind' => 'gpx', 'path' => 'entry.gpx', 'original_name' => 'entry.gpx',
            'sha256' => str_repeat('c', 64), 'bytes' => 9, 'mime' => 'application/gpx+xml',
            'uploaded_by' => $this->admin->id, 'status' => 'ready']);
        $pole->update(['waypoint_reference' => '001', 'gpx_source_id' => $source->id]);
        $start = $batch->waypoints()->create(['source_file_id' => $source->id, 'name' => '001', 'latitude' => 34, 'longitude' => 73]);
        $meter = $batch->waypoints()->create(['source_file_id' => $source->id, 'name' => '002', 'latitude' => 34.0001, 'longitude' => 73]);
        $cable = $transformer->serviceCables()->first();
        $cable->update(['meter_waypoint_id' => $meter->id, 'measured_length_m' => null]);
        $export = app(ServiceCableExcelExport::class);
        $records = $export->records($this->feeder)->where('source', 'PDF survey')->values();
        $expected = app(\App\Services\Mdb\ServiceCableService::class)->length([], $start->toArray(), $meter->toArray());
        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, $records->first()['length_m'], 0.000001);
        $this->assertSame('Coordinate estimate', $records->first()['length_source']);
        $book = $export->workbook($this->feeder, $records);
        try {
            $this->assertEqualsWithDelta($expected, $book->getSheetByName('Length')->getCell('D3')->getValue(), 0.000001);
            $this->assertSame('Coordinate estimate', $book->getSheetByName('Service Cable Records')->getCell('R2')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
        $meter->update(['correction' => ['latitude' => 35, 'longitude' => 73]]);
        $this->assertNull($export->records($this->feeder)->firstWhere('source', 'PDF survey')['length_m']);
    }

    public function test_empty_feeder_is_exportable_and_unassigned_users_cannot_download_another_feeder(): void
    {
        $this->actingAs($this->admin)->get(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]))
            ->assertOk()->assertSee('No saved service cable records');
        $mobile = User::factory()->create(['role' => 'mobile_survey_user']);
        $this->actingAs($mobile)->get(route('service-cable-exports.download', $this->feeder))->assertForbidden();
        $mobile->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('ServiceCables:ExportFeeder', 'web'));
        $this->actingAs($mobile)->get(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]))->assertNotFound();
    }

    public function test_explicit_feeder_export_permission_includes_all_collectors_on_an_accessible_feeder(): void
    {
        $this->survey($this->feeder, 'TR-001', $this->cables(1));
        $secondCollector = User::factory()->create(['role' => 'mobile_survey_user']);
        $survey = $this->survey($this->feeder, 'TR-002', $this->cables(2), 'submitted');
        $survey->update(['collected_by' => $secondCollector->id]);
        $mobile = User::factory()->create(['role' => 'mobile_survey_user']);
        $this->team->members()->attach($mobile->id, ['is_leader' => false]);
        $this->actingAs($mobile)->get(route('service-cable-exports.index'))->assertForbidden();
        $mobile->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('ServiceCables:ExportFeeder', 'web'));
        $this->actingAs($mobile)->get(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]))
            ->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 6);
    }

    public function test_only_checked_transformers_appear_in_every_export_sheet(): void
    {
        foreach (range(1, 3) as $index) $this->survey($this->feeder, 'TR-00'.$index, $this->cables($index));
        $this->survey($this->other, 'OTHER-FEEDER-TR', $this->cables(9));
        $export = app(ServiceCableExcelExport::class);
        $selected = $export->records($this->feeder)->whereIn('transformer', ['TR-001', 'TR-003']);
        $keys = $selected->pluck('transformer_key')->unique()->values()->all();
        $response = $this->actingAs($this->admin)->get(route('service-cable-exports.download', [
            'feeder' => $this->feeder->id, 'transformers' => $keys,
        ]))->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        try {
            $details = $book->getSheetByName('Service Cable Records');
            $this->assertSame(7, $details->getHighestDataRow());
            $actualIds = [];
            foreach (range(2, 7) as $row) {
                $recordId = $details->getCell('A'.$row)->getValue();
                $record = $selected->firstWhere('record_id', $recordId);
                $this->assertNotNull($record);
                $actualIds[] = $recordId;
                $this->assertContains($details->getCell('D'.$row)->getValue(), ['TR-001', 'TR-003']);
                $this->assertSame('0012', $details->getCell('B'.$row)->getValue());
                if ($record['length_m'] === null) $this->assertNull($details->getCell('Q'.$row)->getValue());
                else $this->assertEqualsWithDelta($record['length_m'], $details->getCell('Q'.$row)->getValue(), 0.0000001);
            }
            $this->assertEqualsCanonicalizing($selected->pluck('record_id')->all(), $actualIds);

            $lengths = $book->getSheetByName('Length');
            $this->assertSame('TR-001', $lengths->getCell('B3')->getValue());
            $this->assertSame('TR-003', $lengths->getCell('B4')->getValue());
            $this->assertNull($lengths->getCell('B5')->getValue());
            $this->assertEqualsWithDelta($selected->sum('length_m'), $lengths->getCell('D49')->getValue(), 0.0000001);
            $consumer = $book->getSheetByName('Consumer Sheet');
            $this->assertSame('TR-001', $consumer->getCell('A8')->getValue());
            $this->assertSame('TR-003', $consumer->getCell('A9')->getValue());
            $this->assertNull($consumer->getCell('A10')->getValue());
            $this->assertEqualsWithDelta(50, $consumer->getCell('E8')->getValue(), 0.0000001);
            $this->assertEqualsWithDelta(50, $consumer->getCell('E9')->getValue(), 0.0000001);

            $routes = $book->getSheetByName('Route Points');
            $this->assertSame(7, $routes->getHighestDataRow());
            foreach (range(2, 7) as $row) {
                $this->assertContains($routes->getCell('A'.$row)->getValue(), $actualIds);
                $this->assertContains($routes->getCell('C'.$row)->getValue(), ['TR-001', 'TR-003']);
            }
            $info = $book->getSheetByName('Export Info');
            $this->assertSame(2, $info->getCell('B5')->getValue());
            $this->assertSame(6, $info->getCell('B6')->getValue());
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_download_requires_a_nonempty_valid_transformer_selection_from_the_selected_feeder(): void
    {
        $this->survey($this->feeder, 'TR-001', $this->cables(1));
        $this->survey($this->other, 'OTHER-FEEDER-TR', $this->cables(2));
        $foreignKey = app(ServiceCableExcelExport::class)->records($this->other)->first()['transformer_key'];
        $this->actingAs($this->admin)->from(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]));
        $this->get(route('service-cable-exports.download', $this->feeder))
            ->assertRedirect()->assertSessionHasErrors('transformers');
        $this->get(route('service-cable-exports.download', ['feeder' => $this->feeder->id, 'transformers' => []]))
            ->assertRedirect()->assertSessionHasErrors('transformers');
        foreach ([$foreignKey, 'NONEXISTENT-TRANSFORMER'] as $invalidKey) {
            $this->get(route('service-cable-exports.download', ['feeder' => $this->feeder->id, 'transformers' => [$invalidKey]]))
                ->assertRedirect()->assertSessionHasErrors('transformers.0');
        }
    }

    public function test_transformer_selection_table_displays_saved_capacity_and_leaves_unknown_capacity_uninvented(): void
    {
        $this->survey($this->feeder, 'KNOWN-CAPACITY-TR', $this->cables(1));
        $unknown = $this->survey($this->feeder, 'UNKNOWN-CAPACITY-TR', $this->cables(2));
        $unknown->update(['header' => []]);
        $response = $this->actingAs($this->admin)->get(route('service-cable-exports.index', ['feeder_id' => $this->feeder->id]));
        $response->assertOk()->assertSee('Capacity (kVA)')->assertSee('KNOWN-CAPACITY-TR')->assertSee('UNKNOWN-CAPACITY-TR')
            ->assertSee('name="transformers[]"', false)->assertSee('Not recorded');
        $records = $response->viewData('records');
        $this->assertSame(50, $records->firstWhere('transformer', 'KNOWN-CAPACITY-TR')['rating']);
        $this->assertNull($records->firstWhere('transformer', 'UNKNOWN-CAPACITY-TR')['rating']);
        $this->assertMatchesRegularExpression('/KNOWN-CAPACITY-TR<\/td>\s*<td[^>]*>\s*50\s*<\/td>/s', $response->getContent());
    }
}
