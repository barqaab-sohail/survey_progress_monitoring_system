<?php

namespace Tests\Unit;

use App\Services\SurveyProgress\SourceParser;
use App\Services\SurveyProgress\SpanCalculator;
use App\Services\SurveyProgress\TranscriptionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class SurveyProgressGeometryTest extends TestCase
{
    private string $root = '11131222104';

    private string $a = '01101026001';

    private string $b = '01101026003';

    private string $unused = '01101026002';

    private function point(string $id, float $lon, ?float $elevation): array
    {
        return ['id' => $id, 'lat' => 34.0, 'lon' => $lon, 'elevation' => $elevation];
    }

    private function survey(array $spans, array $points): array
    {
        return ['key' => 'A_10102026_1', 'pdf' => ['roots' => [$this->root], 'spans' => $spans, 'issues' => []], 'gpx' => ['points' => $points, 'issues' => []]];
    }

    public function test_branches_use_explicit_spans_and_reversed_duplicates_do_not_add_distance(): void
    {
        $calculator = new SpanCalculator;
        $root = $this->point($this->root, 73.0, 100.0);
        $a = $this->point($this->a, 73.001, 120.0);
        $b = $this->point($this->b, 73.002, 150.0);
        $survey = $this->survey([['start' => $this->root, 'end' => $this->a], ['start' => $this->root, 'end' => $this->b], ['start' => $this->a, 'end' => $this->root]], [$this->a => [$a], $this->b => [$b], $this->unused => [$this->point($this->unused, 74.0, 500.0)]]);
        $result = $calculator->calculate([$survey], [$this->root => [$root]]);
        $expected = (hypot($calculator->horizontal($root, $a), 20.0) + hypot($calculator->horizontal($root, $b), 50.0)) / 1000;
        $this->assertEqualsWithDelta($expected, $result['confirmed_km'], 0.0000001);
        $this->assertCount(2, $result['spans']);
        $this->assertSame(0, $result['unresolved_spans']);
    }

    public function test_wgs84_equatorial_distance_matches_ellipsoid_reference(): void
    {
        $distance = (new SpanCalculator)->horizontal(['lat' => 0.0, 'lon' => 0.0], ['lat' => 0.0, 'lon' => 0.001]);
        $this->assertEqualsWithDelta(111.319490793, $distance, 0.0001);
    }

    public function test_missing_elevation_is_unverified_without_zero_substitution(): void
    {
        $root = $this->point($this->root, 73.0, null);
        $a = $this->point($this->a, 73.001, 120.0);
        $result = (new SpanCalculator)->calculate([$this->survey([['start' => $this->root, 'end' => $this->a]], [$this->a => [$a]])], [$this->root => [$root]]);
        $this->assertSame(0.0, $result['confirmed_km']);
        $this->assertGreaterThan(0, $result['unverified_horizontal_km']);
        $this->assertNull($result['spans'][0]['distance_m']);
        $this->assertContains('missing_elevation', $result['spans'][0]['reasons']);
    }

    public function test_missing_and_duplicate_coordinates_cannot_be_confirmed(): void
    {
        $root = $this->point($this->root, 73.0, 100.0);
        $a = $this->point($this->a, 73.001, 120.0);
        $result = (new SpanCalculator)->calculate([$this->survey([['start' => $this->root, 'end' => $this->a], ['start' => $this->root, 'end' => $this->b]], [$this->a => [$a, $a]])], [$this->root => [$root]]);
        $this->assertSame(0.0, $result['confirmed_km']);
        $this->assertSame(2, $result['unresolved_spans']);
        $this->assertContains('duplicate_waypoint', $result['spans'][0]['reasons']);
        $this->assertContains('missing_waypoint', $result['spans'][1]['reasons']);
    }

    public function test_disconnected_network_never_becomes_confirmed(): void
    {
        $result = (new SpanCalculator)->calculate([$this->survey([['start' => $this->a, 'end' => $this->b]], [$this->a => [$this->point($this->a, 73.001, 100.0)], $this->b => [$this->point($this->b, 73.002, 120.0)]])], [$this->root => [$this->point($this->root, 73.0, 100.0)]]);
        $this->assertContains('disconnected_branch', $result['spans'][0]['reasons']);
        $this->assertSame(0.0, $result['confirmed_km']);
    }

    public function test_gpx_preserves_leading_zeros_and_missing_elevations(): void
    {
        $result = (new SourceParser)->gpx('<gpx><wpt lat="34" lon="73"><name>01101026001</name></wpt><wpt lat="34" lon="73.1"><name>001</name><ele>20</ele></wpt></gpx>');
        $this->assertArrayHasKey('01101026001', $result['points']);
        $this->assertNull($result['points']['01101026001'][0]['elevation']);
        $this->assertContains('invalid_complete_waypoint', array_column($result['issues'], 'code'));
    }

    public function test_kmz_html_gps_no_and_transformer_type_with_zero_altitude(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sp-kmz-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', '<kml><Document><Placemark><description><![CDATA[<table><tr><td>GPS_No</td><td>11131222104</td></tr><tr><td>Equip_Type</td><td>Transformer</td></tr><tr><td>Pole Number</td><td>11131222104</td></tr><tr><td>Equipment Number</td><td>T-11131222104</td></tr><tr><td>Feeder Code</td><td>000407</td></tr></table>]]></description><Point><altitudeMode>absolute</altitudeMode><coordinates>73,34,0</coordinates></Point></Placemark></Document></kml>');
        $zip->close();
        try {
            $result = (new SourceParser)->kmz($path, '407');
            $this->assertCount(1, $result['roots'][$this->root]);
            $this->assertNull($result['roots'][$this->root][0]['elevation']);
            $this->assertSame([], (new SourceParser)->kmz($path, '408')['roots']);
        } finally {
            unlink($path);
        }
    }

    public function test_incomplete_transcription_can_be_drafted_but_not_reviewed(): void
    {
        $service = new TranscriptionService;
        $this->assertCount(1, $service->parse('11131222104,104,001,1,2', false)['networks']);
        $this->expectException(ValidationException::class);
        $service->parse('11131222104,104,001,1,2', true);
    }
}
