<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Services\Gpx\Calculator;
use App\Services\Gpx\KmzExporter;
use App\Services\Gpx\VerifiedSurvey;
use App\Services\Mdb\SnapshotService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VerifiedGpxSurveyTest extends TestCase
{
    private function batch(bool $broken = false): SurveyBatch
    {
        $rows = [];
        foreach ([[1, 'S', '999'], [1, 'E', '027'], [2, 'S', '027'], [2, 'E', '031'],
            [3, 'S', $broken ? '032' : '031'], [3, 'E', '032'], [4, 'S', '027'], [4, 'E', '034']] as [$pair, $designation, $gps]) {
            $rows[] = ['pair_number' => $pair, 'designation' => $designation, 'waypoint_reference' => $gps,
                'composite_identifier' => '01081026'.$gps, 'row_date' => '2026-10-08', 'gpx_source_id' => 2];
        }
        $points = [];
        foreach (['027', '031', '032', '034', '090'] as $i => $name) {
            $points[] = ['id' => $i + 1, 'source_file_id' => 2, 'name' => $name, 'latitude' => 34,
                'longitude' => 73 + $i * .001, 'elevation' => 100 + $i * 10];
        }
        $snapshot = ['transformers' => [['id' => 5, 'code' => 'TEST', 'entry_rows' => $rows]], 'waypoints' => $points];
        $revision = new Revision(['batch_id' => 17, 'number' => 67, 'snapshot' => $snapshot,
            'sha256' => app(SnapshotService::class)->hash($snapshot)]);
        $revision->sha256 = app(SnapshotService::class)->hash($revision->snapshot);
        $batch = new SurveyBatch(['id' => 17, 'status' => 'approved', 'revision' => 67, 'staged_workflow' => true, 'survey_status' => 'approved']);
        $batch->forceFill(['id' => 17]);
        $batch->setRelation('approvedRevision', $revision);
        return $batch;
    }

    public function test_verified_connections_exclude_unrelated_points_and_support_optional_elevation_sag_and_kmz(): void
    {
        $source = app(VerifiedSurvey::class)->source($this->batch(), 5);
        $this->assertCount(4, $source['points']);
        $this->assertSame(67, $source['survey']['revision']);
        $calculator = app(Calculator::class);
        $settings = array_replace($calculator->defaults(), ['span_sequences' => $source['survey']['span_sequences'],
            'connections_confirmed' => true, 'supports_confirmed' => true]);
        $horizontal = $calculator->calculate($source, $settings);
        $this->assertTrue($horizontal['complete']);
        $this->assertSame(3, $horizontal['span_count']);
        $settings['include_elevation'] = true;
        $elevated = $calculator->calculate($source, $settings);
        $this->assertGreaterThan($horizontal['final_m'], $elevated['final_m']);
        $settings['include_sag'] = true;
        $settings['default_sag'] = 1;
        $sag = $calculator->calculate($source, $settings);
        $this->assertGreaterThan($elevated['final_m'], $sag['final_m']);
        $kml = app(KmzExporter::class)->kml('TEST', $source, $settings, $sag);
        $this->assertStringContainsString('revision_sha256', $kml);
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($kml));
    }

    public function test_broken_network_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(VerifiedSurvey::class)->source($this->batch(true), 5);
    }

    public function test_superseded_approval_is_rejected(): void
    {
        $batch = $this->batch();
        $batch->revision = 68;
        $this->expectException(ValidationException::class);
        app(VerifiedSurvey::class)->source($batch, 5);
    }

    public function test_missing_coordinates_are_rejected(): void
    {
        $batch = $this->batch();
        $snapshot = $batch->approvedRevision->snapshot;
        $snapshot['waypoints'] = [];
        $batch->approvedRevision->snapshot = $snapshot;
        $batch->approvedRevision->sha256 = app(SnapshotService::class)->hash($snapshot);
        $this->expectException(ValidationException::class);
        app(VerifiedSurvey::class)->source($batch, 5);
    }
}

