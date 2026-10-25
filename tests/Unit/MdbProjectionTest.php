<?php

namespace Tests\Unit;

use App\Services\Mdb\GpxParser;
use App\Services\Mdb\ProjectionService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MdbProjectionTest extends TestCase
{
    public function test_utm_projection_does_not_depend_on_a_python_installation_and_handles_both_hemispheres(): void
    {
        config(['mdb_workflow.projection.python' => 'missing-python-for-test']);
        $service = new ProjectionService;
        $north = $service->project(56, 12, 32632);
        self::assertEqualsWithDelta(687071.44, $north['x'], 0.01);
        self::assertEqualsWithDelta(6210141.33, $north['y'], 0.01);
        $south = $service->project(-44, 174, 32759);
        self::assertEqualsWithDelta(740526.32, $south['x'], 0.01);
        self::assertEqualsWithDelta(5123750.87, $south['y'], 0.01);
        self::assertSame([], $service->projectMany([], 32643));
        $equator = $service->project(0, 75, 32643);
        self::assertEqualsWithDelta(500000, $equator['x'], 1e-8);
        self::assertEqualsWithDelta(0, $equator['y'], 1e-8);
    }

    public function test_proj_matches_known_utm_and_length_with_intermediate_geometry(): void
    {
        $service = new ProjectionService;
        $point = $service->project(31.7, 73.5, 32643);
        self::assertEqualsWithDelta(357850.278072, $point['x'], 0.001);
        self::assertEqualsWithDelta(3508161.644553, $point['y'], 0.001);
        $points = [['latitude' => 31.7, 'longitude' => 73.5], ['latitude' => 31.7, 'longitude' => 73.5005], ['latitude' => 31.7, 'longitude' => 73.501]];
        self::assertEqualsWithDelta(94.785206, $service->length($points, 32643), 0.01);
    }

    public function test_projection_rejects_non_projected_and_wrong_zone_crs(): void
    {
        $service = new ProjectionService;
        foreach ([4326, 32640] as $epsg) {
            try {
                $service->project(31.7, 73.5, $epsg);
                self::fail('Invalid or geographically inappropriate CRS should fail.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Projection failed', $exception->getMessage());
            }
        }
    }

    public function test_invalid_coordinates_are_rejected_before_worker_execution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProjectionService)->project(91, 73.5, 32643);
    }

    public function test_elevation_adjusted_lengths_include_zero_negative_and_descending_elevations(): void
    {
        foreach ([[0, 10], [10, 0], [-10, 0], [0, -10]] as [$from, $to]) {
            $lengths = ProjectionService::lengthFromProjected([['elevation' => $from], ['elevation' => $to]],
                [['x' => 0, 'y' => 0], ['x' => 30, 'y' => 0]]);
            self::assertEqualsWithDelta(sqrt(1000), $lengths['length_m'], 1e-10);
            self::assertSame(30.0, $lengths['horizontal_length_m']);
            self::assertSame('elevation_adjusted', $lengths['elevation_status']);
        }
    }

    public function test_missing_or_invalid_elevation_falls_back_without_assuming_zero(): void
    {
        foreach ([null, '', 'invalid', INF, NAN] as $unavailable) {
            $lengths = ProjectionService::lengthFromProjected([['elevation' => 100], ['elevation' => $unavailable]],
                [['x' => 0, 'y' => 0], ['x' => 30, 'y' => 0]]);
            self::assertSame(30.0, $lengths['length_m']);
            self::assertSame($unavailable === null || $unavailable === '' ? 'horizontal_missing_elevation' : 'horizontal_invalid_elevation', $lengths['elevation_status']);
        }
    }

    public function test_intermediate_geometry_uses_each_elevation_change_and_requires_all_elevations(): void
    {
        $points = [['elevation' => 0], ['elevation' => 10], ['elevation' => 0]];
        $xy = [['x' => 0, 'y' => 0], ['x' => 30, 'y' => 0], ['x' => 60, 'y' => 0]];
        self::assertEqualsWithDelta(2 * sqrt(1000), ProjectionService::lengthFromProjected($points, $xy)['length_m'], 1e-10);
        unset($points[1]['elevation']);
        $lengths = ProjectionService::lengthFromProjected($points, $xy);
        self::assertSame(60.0, $lengths['length_m']);
        self::assertSame('horizontal_missing_elevation', $lengths['elevation_status']);
    }

    public function test_real_projection_uses_gpx_elevations_in_metres(): void
    {
        $parsed = (new GpxParser)->parse('<gpx xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31.7" lon="73.5"><ele>0</ele><name>001</name></wpt><wpt lat="31.7" lon="73.501"><ele>10</ele><name>002</name></wpt></gpx>');
        $service = new ProjectionService;
        $details = $service->lengthDetails($parsed['waypoints'], 32643);
        self::assertEqualsWithDelta(94.785206, $details['horizontal_length_m'], 0.01);
        self::assertEqualsWithDelta(hypot($details['horizontal_length_m'], 10), $service->length($parsed['waypoints'], 32643), 1e-8);
        self::assertSame('elevation_adjusted', $details['elevation_status']);
    }
}
