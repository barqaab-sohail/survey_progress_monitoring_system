<?php

namespace Tests\Unit;

use App\Services\FeederKmzService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeederKmzServiceTest extends TestCase
{
    public function test_lt_export_preserves_ht_features_and_excludes_unconnected_meter_points(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['waypoints'][] = ['id' => 999, 'name' => 'METER-ONLY', 'source_file_id' => 1, 'latitude' => 34.5, 'longitude' => 73.5];
        $service = app(FeederKmzService::class);
        $network = $service->network($snapshot['transformers'][0], $snapshot['waypoints']);
        $this->assertCount(2, $network['lines']);
        $this->assertCount(3, $network['points']);
        $network += ['registered_transformer_id' => 1, 'batch_id' => 1, 'revision' => 2, 'revision_sha256' => str_repeat('a', 64)];
        $xml = $service->appendLt('<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Style id="ht"><LineStyle><color>ff0000ff</color></LineStyle></Style><Folder><name>Original HT</name><Placemark id="original"><name>HT line</name><LineString><coordinates>73,34,0 73.1,34.1,0</coordinates></LineString></Placemark></Folder></Document></kml>', [$network]);
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $this->assertSame(1, $xp->query('//*[@id="original"]')->length);
        $this->assertSame(3, $xp->query('//*[local-name()="LineString"]')->length);
        $this->assertSame(3, $xp->query('//*[local-name()="Point"]')->length);
        $this->assertStringContainsString('73,34,0 73.1,34.1,0', $xml);
        $this->assertStringNotContainsString('METER-ONLY', $xml);
        $this->assertStringContainsString('RegisteredTransformerId', $xml);
    }

    public function test_missing_coordinates_block_lt_export(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['waypoints'][1]['latitude'] = null;
        $this->expectException(ValidationException::class);
        app(FeederKmzService::class)->network($snapshot['transformers'][0], $snapshot['waypoints']);
    }

    public function test_recorded_lt_route_geometry_is_kept_in_the_kmz(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['transformers'][0]['sections'][1]['geometry'] = [['latitude' => 34.164, 'longitude' => 73.2575]];
        $service = app(FeederKmzService::class);
        $network = $service->network($snapshot['transformers'][0], $snapshot['waypoints']);
        $this->assertCount(3, $network['lines'][1]['route']);
        $this->assertSame(73.2575, $network['lines'][1]['route'][1]['longitude']);
    }

    public function test_disconnected_distribution_sections_are_rejected(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['transformers'][0]['sections'][] = ['id' => 99, 'start_waypoint_id' => 98, 'end_waypoint_id' => 99];
        $snapshot['waypoints'][] = ['id' => 98, 'name' => '098', 'latitude' => 34.5, 'longitude' => 73.5];
        $snapshot['waypoints'][] = ['id' => 99, 'name' => '099', 'latitude' => 34.6, 'longitude' => 73.6];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('disconnected');
        app(FeederKmzService::class)->network($snapshot['transformers'][0], $snapshot['waypoints']);
    }
    public function test_lt_balloons_show_recorded_pole_details_line_lengths_and_safe_survey_metadata(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['transformers'][0]['header']['location'] = '<script>alert(1)</script> Street';
        $snapshot['transformers'][0]['sections'][1]['measured_length_m'] = 28.75;
        $snapshot['transformers'][0]['entry_rows'][3]['pole_height'] = 31;
        $service = app(FeederKmzService::class);
        $network = $service->network($snapshot['transformers'][0], $snapshot['waypoints']);
        $network += ['registered_transformer_id' => 1, 'batch_id' => 5, 'revision' => 2, 'revision_sha256' => str_repeat('a', 64),
            'survey_date' => '2026-11-08', 'approval_date' => '2026-11-11T08:00:00Z'];
        $this->assertSame(28.75, $network['lines'][1]['length_m']);
        $this->assertSame('Recorded measured length', $network['lines'][1]['length_method']);
        $xml = $service->appendLt('<kml xmlns="http://www.opengis.net/kml/2.2"><Document/></kml>', [$network]);
        $doc = new \DOMDocument; $doc->loadXML($xml); $xp = new \DOMXPath($doc);
        $line = $xp->query('//*[local-name()="Placemark"][*[local-name()="LineString"]]')->item(1);
        $description = $xp->query('./*[local-name()="description"]', $line)->item(0)->textContent;
        $this->assertStringContainsString('28.75', $description);
        $this->assertStringContainsString('2026-11-11T08:00:00Z', $description);
        $this->assertStringContainsString('Start waypoint', $description);
        $this->assertStringContainsString('Conductors', $description);
        $this->assertStringNotContainsString('<script>', $description);
        $this->assertStringContainsString('&lt;script&gt;', $description);
        $height = $xp->query('//*[local-name()="Placemark"]/*[local-name()="ExtendedData"]/*[local-name()="Data"][@name="Pole height"]/*[local-name()="value"]');
        $this->assertContains('31', array_map(fn ($n) => $n->textContent, iterator_to_array($height)));
    }

}
