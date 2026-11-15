<?php
namespace Tests\Feature;

use App\Http\Requests\SyncFieldSurveyRequest;
use App\Services\FieldSurveyEntryValidation;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MobileEntryValidationParityTest extends TestCase
{
    public function test_mobile_height_boundaries_and_st_phase_contract(): void
    {
        $request = SyncFieldSurveyRequest::create('/', 'POST', ['status' => 'submitted']);
        $rules = $request->rules();
        foreach ([0, 29.99, 30, 10000, 10000.01] as $height) {
            $validator = Validator::make(['height' => $height], ['height' => $rules['rows.*.pole_height_ft']]);
            $this->assertSame($height >= 30 && $height <= 10000, $validator->passes());
        }
        $this->assertContains('array:rs,sc,st', $rules['rows.*.consumer_phases']);
        $this->assertArrayHasKey('rows.*.consumer_phases.st.R', $rules);
        $this->assertTrue(Validator::make(['c' => '__none__'], ['c' => $rules['rows.*.conductor_r']])->passes());
        $this->assertFalse(Validator::make(['c' => 'invented-code'], ['c' => $rules['rows.*.conductor_r']])->passes());
    }

    public function test_waypoint_confirmation_must_match_group_date_and_identifier(): void
    {
        $rows = [$this->row('a', 'S', '104'), $this->row('a', 'E', '001')];
        $rows[0]['waypoint_check_required'] = true;
        $rows[1]['waypoint_check_required'] = true;
        $service = app(FieldSurveyEntryValidation::class);
        $this->assertArrayHasKey('rows.0.waypoint_confirmation', $service->errors(['rows' => $rows]));
        foreach ($rows as &$row) $row['waypoint_confirmation'] = $row['group'].'|'.$row['date'].'|'.$row['gps_waypoint'];
        unset($row);
        $this->assertSame([], $service->errors(['rows' => $rows]));
        $rows[1]['gps_waypoint'] = '002';
        $this->assertArrayHasKey('rows.1.waypoint_confirmation', $service->errors(['rows' => $rows]));
        $request = SyncFieldSurveyRequest::create('/', 'POST', ['status' => 'submitted']);
        $rules = $request->rules();
        $this->assertArrayHasKey('rows.*.waypoint_confirmation', $rules);
        $this->assertArrayHasKey('rows.*.waypoint_check_required', $rules);
    }

    private function row(string $pair, string $se, string $gps, string $conductor = 'ANT'): array
    {
        return ['pair_id' => $pair, 'se' => $se, 'group' => '03', 'date' => '2026-01-01', 'gps_waypoint' => $gps, 'conductor_r' => $conductor];
    }

    public function test_connected_branches_are_allowed_and_disconnected_or_zero_spans_are_rejected(): void
    {
        $rows = [$this->row('a','S','000'), $this->row('a','E','001'),
            $this->row('b','S','001'), $this->row('b','E','002'),
            $this->row('c','S','001'), $this->row('c','E','003')];
        $service = app(FieldSurveyEntryValidation::class);
        $this->assertSame([], $service->errors(['rows' => $rows]));
        $rows[4]['gps_waypoint'] = '099'; $rows[5]['gps_waypoint'] = '098';
        $this->assertArrayHasKey('rows.4.gps_waypoint', $service->errors(['rows' => $rows]));
        $rows[4]['gps_waypoint'] = '001'; $rows[5]['gps_waypoint'] = '001';
        $this->assertArrayHasKey('rows.4.gps_waypoint', $service->errors(['rows' => $rows]));
    }

    public function test_pair_conductors_and_pv_identification(): void
    {
        $data = ['rows' => [$this->row('a','S','000'), $this->row('a','E','001','WASP')], 'solar' => [['installed_pv_kw' => 5]]];
        $service = app(FieldSurveyEntryValidation::class);
        $errors = $service->errors($data);
        $this->assertArrayHasKey('rows.1.conductor_r', $errors);
        $this->assertArrayHasKey('solar.0.consumer_reference', $errors);
        $data['rows'][1]['conductor_r'] = 'ANT';
        $data['solar'][0]['meter_number'] = 'M123';
        $this->assertSame([], $service->errors($data));
    }
}
