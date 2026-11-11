<?php

namespace Tests\Unit;

use App\Services\FeederKmzHistoryService;
use App\Services\FeederKmzPreviewService;
use Tests\TestCase;

class FeederKmzPreviewTest extends TestCase
{
    public function test_preview_preserves_multi_geometry_and_skips_invalid_lines_without_bridging_them(): void
    {
        $xml = '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><name>HT asset</name><MultiGeometry><Point><coordinates>73,34,0</coordinates></Point><LineString><coordinates>73,34 73.1,34.1</coordinates></LineString></MultiGeometry></Placemark><Placemark><LineString><coordinates>73,34 bad,data 73.2,34.2</coordinates></LineString></Placemark><Placemark><Polygon><outerBoundaryIs><LinearRing><coordinates>73,34 73.1,34 73.1,34.1</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark></Document></kml>';
        $result = app(FeederKmzPreviewService::class)->fromKml($xml);
        $this->assertCount(3, $result['features']);
        $this->assertSame(1, $result['skipped_geometries']);
        $this->assertSame([73.0, 34.0], $result['features'][0]['geometry']['coordinates']);
        $this->assertSame('ht', $result['features'][1]['properties']['layer']);
        $ring = $result['features'][2]['geometry']['coordinates'][0];
        $this->assertSame($ring[0], $ring[array_key_last($ring)]);
    }

    public function test_history_comparison_detects_revised_renamed_added_and_removed_networks(): void
    {
        $old = [['registered_transformer_id'=>1,'code'=>'A','revision_sha256'=>'old'], ['registered_transformer_id'=>2,'code'=>'B','revision_sha256'=>'same']];
        $new = [['registered_transformer_id'=>1,'code'=>'A','revision_sha256'=>'new'], ['registered_transformer_id'=>3,'code'=>'C','revision_sha256'=>'new']];
        $service = app(FeederKmzHistoryService::class);
        $this->assertSame(['added'=>['C'],'removed'=>['B'],'updated'=>['A']], $service->changes($old, $new));
        $this->assertSame(['added'=>[],'removed'=>[],'updated'=>[]], $service->changes($new, $new));
        $renamed = $new; $renamed[0]['code'] = 'A-renamed';
        $this->assertSame(['A-renamed'], $service->changes($new, $renamed)['updated']);
    }
}
