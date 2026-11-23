<?php

namespace Tests\Unit;

use App\Services\ServiceCableRoute;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceCableRouteTest extends TestCase
{
    public function test_bent_route_sums_the_observed_segments_and_overwrites_client_total(): void
    {
        $points = [
            ['latitude' => 0, 'longitude' => 1, 'waypoint' => 'POLE'],
            ['latitude' => 0, 'longitude' => 1.001, 'waypoint' => 'TURN'],
            ['latitude' => 0.001, 'longitude' => 1.001, 'waypoint' => 'METER'],
        ];
        $route = app(ServiceCableRoute::class)->normalize(['route_points' => $points, 'route_length_m' => 1]);
        $this->assertEqualsWithDelta(222.390, $route['route_length_m'], 0.001);
        $direct = app(ServiceCableRoute::class)->normalize(['route_points' => [$points[0], $points[2]]]);
        $this->assertGreaterThan($direct['route_length_m'], $route['route_length_m']);
        $this->assertSame(['POLE', 'TURN', 'METER'], array_column($route['route_points'], 'waypoint'));
    }

    public function test_absent_route_never_accepts_a_client_route_total_and_keeps_recorded_measurement(): void
    {
        $this->assertSame(['measured_length_m' => 40.5], app(ServiceCableRoute::class)->normalize([
            'route_length_m' => 900, 'measured_length_m' => 40.5,
        ]));
        $this->assertSame(['route_points' => [], 'route_length_m' => null], app(ServiceCableRoute::class)->normalize(['route_points' => []]));
    }

    public function test_one_point_and_duplicate_or_invalid_coordinates_are_rejected(): void
    {
        foreach ([
            [['latitude' => 34, 'longitude' => 73]],
            [['latitude' => 34, 'longitude' => 73], ['latitude' => 34, 'longitude' => 73]],
            [['latitude' => 0, 'longitude' => 0], ['latitude' => 34, 'longitude' => 73]],
            [['latitude' => 91, 'longitude' => 73], ['latitude' => 34, 'longitude' => 73]],
            [['longitude' => 73], ['latitude' => 34, 'longitude' => 73]],
        ] as $points) {
            try {
                app(ServiceCableRoute::class)->normalize(['route_points' => $points]);
                $this->fail('An incomplete or invalid route must not be saved.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
