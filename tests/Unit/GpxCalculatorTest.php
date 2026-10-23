<?php

namespace Tests\Unit;

use App\Services\Gpx\Calculator;
use App\Services\Gpx\KmzExporter;
use App\Services\Gpx\Parser;
use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class GpxCalculatorTest extends TestCase
{
    private function source(string $body): array
    {
        return (new Parser)->parse('<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1">'.$body.'</gpx>');
    }

    private function route(): array
    {
        return $this->source('<rte><name>Route &amp; One</name><rtept lat="0" lon="0"><name>A</name><ele>100</ele></rtept><rtept lat="0" lon="0.001"><name>B</name><ele>110</ele></rtept></rte>');
    }

    private function calculate(array $source, array $settings = []): array
    {
        return app(Calculator::class)->calculate($source, $settings);
    }

    public function test_four_independent_modes_and_zero_sag_use_wgs84_and_per_span_slope(): void
    {
        $source = $this->route();
        $defaults = app(Calculator::class)->defaults();
        $this->assertFalse($defaults['include_elevation']);
        $this->assertFalse($defaults['include_sag']);
        $horizontal = $this->calculate($source);
        $h = 111.31949079327357; // WGS84 equatorial arc: a*pi/180*0.001.
        $this->assertEqualsWithDelta($h, $horizontal['final_m'], 0.00001);
        $elevation = $this->calculate($source, ['include_elevation' => true]);
        $this->assertEqualsWithDelta(hypot($h, 10), $elevation['final_m'], 0.00001);
        $support = ['span_sequences' => 'p1,p2', 'connections_confirmed' => true, 'supports_confirmed' => true, 'include_sag' => true, 'default_sag' => 2];
        foreach ([false, true] as $includeElevation) {
            $result = $this->calculate($source, $support + ['include_elevation' => $includeElevation]);
            $dz = $includeElevation ? 10 : 0;
            // Independent analytic integral of the parabolic derivative.
            $primitive = fn ($u) => ($u * hypot($h, $u) + $h * $h * asinh($u / $h)) / 2;
            $expected = ($primitive($dz + 8) - $primitive($dz - 8)) / 16;
            $this->assertEqualsWithDelta($expected, $result['final_m'], 0.00001);
            $this->assertEqualsWithDelta($result['final_m'] - hypot($h, $dz), $result['sag_additional_m'], 0.00001);
            $zero = $this->calculate($source, array_replace($support, ['default_sag' => 0, 'include_elevation' => $includeElevation]));
            $straight = $includeElevation ? $elevation : $horizontal;
            $this->assertSame($straight['final_m'], $zero['final_m']);
            $this->assertSame(0.0, $zero['sag_additional_m']);
        }
    }

    public function test_track_samples_are_not_individually_sagged_when_supports_skip_samples(): void
    {
        $source = $this->source('<trk><trkseg><trkpt lat="0" lon="0"><ele>0</ele></trkpt><trkpt lat="0" lon="0.0005"><ele>20</ele></trkpt><trkpt lat="0" lon="0.001"><ele>10</ele></trkpt></trkseg></trk>');
        $this->assertSame(2, $this->calculate($source)['valid_spans']);
        $result = $this->calculate($source, ['span_sequences' => 'p1,p3', 'connections_confirmed' => true, 'supports_confirmed' => true, 'include_elevation' => true,
            'include_sag' => true, 'default_sag' => 1, 'sag_overrides' => ['p1_p3' => 2]]);
        $this->assertSame(1, $result['valid_spans']);
        $this->assertSame(2.0, $result['spans'][0]['sag_m']);
        $this->assertSame(10.0, $result['spans'][0]['elevation_difference_m']);
    }

    public function test_routes_tracks_and_segments_remain_disconnected_and_ordered(): void
    {
        $source = $this->source('<wpt lat="20" lon="20"/><rte><name>First</name><rtept lat="0" lon="0"/><rtept lat="0" lon="0.001"/></rte><rte><rtept lat="10" lon="0"/><rtept lat="10" lon="0.001"/></rte><trk><name>Separate</name><trkseg><trkpt lat="30" lon="0"/><trkpt lat="30" lon="0.001"/></trkseg><trkseg><trkpt lat="40" lon="0"/><trkpt lat="40" lon="0.001"/></trkseg></trk>');
        $result = $this->calculate($source);
        $this->assertSame(4, $result['valid_spans']);
        $this->assertSame(['p2', 'p4', 'p6', 'p8'], array_column(array_column($result['spans'], 'start'), 'id'));
        $this->assertLessThan(450, $result['final_m']);
        $this->assertCount(4, array_unique(array_column($result['spans'], 'group')));
    }

    public function test_cross_segment_or_reverse_order_custom_sequences_are_rejected(): void
    {
        $source = $this->source('<trk><trkseg><trkpt lat="0" lon="0"/><trkpt lat="0" lon="1"/></trkseg><trkseg><trkpt lat="1" lon="0"/></trkseg></trk>');
        foreach (['p2,p3', 'p2,p1'] as $sequence) {
            try {
                $this->calculate($source, ['span_sequences' => $sequence, 'connections_confirmed' => true]);
                $this->fail('Cross-segment/reverse sequences must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('span_sequences', $exception->errors());
            }
        }
    }

    public function test_missing_elevation_requires_explicit_fallback_or_exclusion_including_with_sag(): void
    {
        $source = $this->source('<rte><rtept lat="0" lon="0"><ele>100</ele></rtept><rtept lat="0" lon="0.001"/><rtept lat="0" lon="0.002"><ele>120</ele></rtept><rtept lat="0" lon="0.003"><ele>125</ele></rtept></rte>');
        $this->assertNull($source['points']['p2']['elevation']);
        $result = $this->calculate($source, ['include_elevation' => true]);
        $this->assertNull($result['final_m']);
        $this->assertSame(2, $result['blocked_spans']);
        $fallback = $this->calculate($source, ['include_elevation' => true, 'missing_elevation' => 'horizontal']);
        $this->assertSame(3, $fallback['valid_spans']);
        $this->assertSame('horizontal_fallback', $fallback['spans'][0]['status']);
        $excluded = $this->calculate($source, ['include_elevation' => true, 'missing_elevation' => 'exclude']);
        $this->assertSame(2, $excluded['excluded_spans']);
        $this->assertSame(1, $excluded['valid_spans']);
        $this->assertTrue($excluded['complete']);
        $sag = $this->calculate($source, ['span_sequences' => 'p1,p2,p3,p4', 'connections_confirmed' => true, 'supports_confirmed' => true, 'include_sag' => true,
            'default_sag' => 2, 'include_elevation' => true, 'missing_elevation' => 'horizontal']);
        $this->assertGreaterThan($fallback['final_m'], $sag['final_m']);
    }

    public function test_invalid_point_breaks_connectivity_and_duplicate_and_long_span_warnings_are_retained(): void
    {
        $source = $this->source('<rte><rtept lat="0" lon="0"><name>A</name></rtept><rtept lat="91" lon="0"/><rtept lat="0" lon="0"><name>A</name></rtept><rtept lat="0" lon="0.1"/></rte>');
        $result = $this->calculate($source);
        $this->assertSame(3, $result['span_count']);
        $this->assertSame(2, $result['blocked_spans']);
        $this->assertSame(1, $result['valid_spans']);
        $this->assertNull($result['final_m']);
        $warnings = implode(' ', $result['warnings']);
        $this->assertStringContainsString('duplicate coordinates', $warnings);
        $this->assertStringContainsString('duplicate name', $warnings);
        $this->assertStringContainsString('Unusually long span', $warnings);
    }

    public function test_sample_is_120_waypoints_with_no_inferred_spans_and_requires_confirmed_sequences(): void
    {
        $source = (new Parser)->parse(file_get_contents(base_path('tests/Fixtures/gpx/Waypoints_2026-10-08.gpx')));
        $this->assertCount(120, $source['points']);
        $this->assertCount(1, $source['groups']);
        $this->assertSame('waypoints', $source['groups'][0]['kind']);
        $this->assertSame('001', $source['points']['p1']['name']);
        $this->assertSame('120', $source['points']['p120']['name']);
        $this->assertCount(120, array_filter($source['points'], fn ($p) => $p['valid'] && $p['elevation'] !== null));
        $result = $this->calculate($source);
        $this->assertSame(0, $result['span_count']);
        $this->assertFalse($result['complete']);
        $pending = $this->calculate($source, ['span_sequences' => "p1,p2,p3\np2,p4"]);
        $this->assertSame(3, $pending['blocked_spans']);
        // Test-only connectivity; these are not claims about the actual surveyed network.
        foreach ([false, true] as $elevation) {
            foreach ([false, true] as $sag) {
                $verified = $this->calculate($source, ['span_sequences' => "p1,p2,p3\np2,p4", 'connections_confirmed' => true,
                    'supports_confirmed' => true, 'include_elevation' => $elevation, 'include_sag' => $sag, 'default_sag' => 1]);
                $this->assertSame(3, $verified['valid_spans']);
                $this->assertGreaterThan(0, $verified['final_m']);
                $path = (new KmzExporter)->write('Sample test', $source, array_replace(app(Calculator::class)->defaults(), ['include_elevation' => $elevation, 'include_sag' => $sag]), $verified);
                $zip = new ZipArchive;
                try {
                    $this->assertTrue($zip->open($path));
                    $this->assertSame(1, $zip->numFiles);
                    $doc = new DOMDocument;
                    $this->assertTrue($doc->loadXML($zip->getFromName('doc.kml'), LIBXML_NONET));
                    $this->assertTrue($doc->schemaValidate(base_path('tests/Fixtures/kml22/ogckml22.xsd')));
                    $xpath = new DOMXPath($doc);
                    $xpath->registerNamespace('k', 'http://www.opengis.net/kml/2.2');
                    $this->assertSame(120, $xpath->query('//k:Point')->length);
                    $this->assertSame(3, $xpath->query('//k:LineString')->length);
                    $this->assertStringContainsString(json_encode($verified['final_m']), $doc->textContent);
                } finally {
                    $zip->close();
                    unlink($path);
                }
            }
        }
    }

    public function test_sag_requires_confirmed_support_spans_and_duplicate_branches_cannot_double_count(): void
    {
        foreach ([['include_sag' => true, 'default_sag' => 2], ['include_sag' => true, 'default_sag' => 2, 'span_sequences' => 'p1,p2', 'connections_confirmed' => true]] as $options) {
            try {
                $this->calculate($this->route(), $options);
                $this->fail('Unconfirmed support endpoints must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('supports_confirmed', $exception->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $this->calculate($this->route(), ['span_sequences' => "p1,p2\np1,p2", 'connections_confirmed' => true]);
    }

    public function test_xml_entities_dtds_non_gpx_and_encoded_declarations_are_rejected(): void
    {
        foreach (['<!DOCTYPE gpx [<!ENTITY file SYSTEM "file:///etc/passwd">]><gpx/>', '<notgpx/>', '<gpx>', "<\0!\0D\0O\0C\0T\0Y\0P\0E\0"] as $xml) {
            try {
                (new Parser)->parse($xml);
                $this->fail('Unsafe or malformed input must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('gpx', $exception->errors());
            }
        }
    }

    public function test_zero_sag_preserves_vertical_only_straight_result_and_nonconvergent_geodesics_are_blocked(): void
    {
        $source = $this->source('<rte><rtept lat="0" lon="0"><ele>10</ele></rtept><rtept lat="0" lon="0"><ele>20</ele></rtept></rte>');
        $settings = ['span_sequences' => 'p1,p2', 'connections_confirmed' => true, 'supports_confirmed' => true, 'include_elevation' => true];
        $straight = $this->calculate($source, $settings);
        $zero = $this->calculate($source, $settings + ['include_sag' => true, 'default_sag' => 0]);
        $this->assertSame(10.0, $straight['final_m']);
        $this->assertSame($straight['final_m'], $zero['final_m']);
        $undefined = $this->calculate($source, $settings + ['include_sag' => true, 'default_sag' => 1]);
        $this->assertNull($undefined['final_m']);
        $far = $this->source('<rte><rtept lat="0" lon="0"/><rtept lat="0.5" lon="179.7"/></rte>');
        $result = $this->calculate($far);
        $this->assertNull($result['final_m']);
        $this->assertSame('geodesic_review_required', $result['spans'][0]['status']);
    }

    public function test_kml_coordinates_altitude_modes_full_precision_and_safe_descriptions(): void
    {
        $source = $this->route();
        $source['points']['p1']['name'] = '<script>alert(1)</script> & "A"';
        foreach ([false, true] as $elevation) {
            $settings = array_replace(app(Calculator::class)->defaults(), ['include_elevation' => $elevation]);
            $result = $this->calculate($source, $settings);
            $xml = (new KmzExporter)->kml('Route & One', $source, $settings, $result);
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_NONET);
            $this->assertTrue($doc->schemaValidate(base_path('tests/Fixtures/kml22/ogckml22.xsd')));
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('k', 'http://www.opengis.net/kml/2.2');
            $this->assertSame($elevation ? 'absolute' : 'clampToGround', $xpath->evaluate('string(//k:LineString/k:altitudeMode)'));
            $this->assertSame($elevation ? '0.0,0.0,100.0 0.001,0.0,110.0' : '0.0,0.0,0.0 0.001,0.0,0.0', $xpath->evaluate('string(//k:LineString/k:coordinates)'));
            $this->assertSame(json_encode($result['spans'][0]['final_m']), $xpath->evaluate('string(//k:Placemark/k:ExtendedData/k:Data[@name="final_m"]/k:value)'));
            $description = $xpath->evaluate('string(//k:Placemark/k:description)');
            $this->assertStringNotContainsString('<script>', $description);
            $this->assertStringContainsString('&lt;script&gt;', $description);
            $this->assertStringContainsString('sag is attributes only', $description);
        }
    }
}
