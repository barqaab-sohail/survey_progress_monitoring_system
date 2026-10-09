<?php

namespace Tests\Unit;

use App\Services\Mdb\ProjectionService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MdbProjectionTest extends TestCase
{
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
}
