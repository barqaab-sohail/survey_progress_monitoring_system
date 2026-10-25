<?php

namespace Tests\Unit;

use App\Models\Transformer;
use App\Services\Mdb\StartingPoleCoordinates;
use App\Services\Mdb\SurveyWaypoint;
use Tests\TestCase;

class MdbStartingPoleCoordinatesTest extends TestCase
{
    private function record(array $values = []): Transformer
    {
        return (new Transformer)->setRawAttributes($values + ['id' => 42, 'feeder_id' => 7, 'transformer_code' => 'T-test',
            'latitude' => '34.1234567', 'longitude' => '73.7654321', 'altitude' => '1012.25', 'raw_attributes' => null], true);
    }

    public function test_named_coordinates_and_optional_altitude_are_captured_without_rounding_or_mutation(): void
    {
        $service = new StartingPoleCoordinates;
        $record = $this->record(['raw_attributes' => json_encode(['PointX' => 73.7654321, 'PointY' => 34.1234567])]);
        $before = $record->getRawOriginal();
        $choice = $service->capture($record);
        $this->assertSame(StartingPoleCoordinates::REGISTERED, $choice['source']);
        $this->assertSame(34.1234567, $choice['latitude']);
        $this->assertSame(73.7654321, $choice['longitude']);
        $this->assertSame(1012.25, $choice['elevation']);
        $this->assertSame($before, $record->getRawOriginal());
        $this->assertNull($service->capture($this->record(['altitude' => null]))['elevation']);
        $this->assertNull($service->capture($this->record(['altitude' => 'invalid']))['elevation']);
        $this->assertSame(StartingPoleCoordinates::REGISTERED, $service->capture($this->record(['latitude' => 0, 'longitude' => 71]))['source']);
    }

    public function test_missing_invalid_nonfinite_null_island_or_conflicting_coordinate_order_falls_back(): void
    {
        $service = new StartingPoleCoordinates;
        $this->assertSame(SurveyWaypoint::FIRST_PAIR_COORDINATE_SOURCE, $service->capture(null)['source']);
        foreach ([['latitude' => null], ['longitude' => null], ['latitude' => ''], ['longitude' => 'invalid'],
            ['latitude' => 91], ['longitude' => -181], ['latitude' => INF], ['longitude' => NAN],
            ['latitude' => 0, 'longitude' => 0], ['latitude' => 121, 'longitude' => 34],
            ['latitude' => 73.7654321, 'longitude' => 34.1234567, 'raw_attributes' => json_encode(['PointX' => 73.7654321, 'PointY' => 34.1234567])],
            ['raw_attributes' => json_encode(['Latitude' => 35, 'Longitude' => 73.7654321])]] as $values) {
            $this->assertSame(SurveyWaypoint::FIRST_PAIR_COORDINATE_SOURCE, $service->capture($this->record($values))['source']);
        }
    }

    public function test_only_a_valid_frozen_choice_for_the_selected_record_is_used(): void
    {
        $service = new StartingPoleCoordinates;
        $transformer = ['header' => ['registered_transformer_id' => 42, 'starting_pole_coordinates' => $service->capture($this->record())]];
        $this->assertNotNull($service->registered($transformer));
        $this->assertNull($service->registered(['header' => ['registered_transformer_id' => 42]]));
        $transformer['header']['registered_transformer_id'] = 99;
        $this->assertNull($service->registered($transformer));
        $transformer['header']['registered_transformer_id'] = 42;
        $transformer['header']['starting_pole_coordinates']['latitude'] = 91;
        $this->assertNull($service->registered($transformer));
        $transformer['header']['starting_pole_coordinates'] += ['correction' => ['latitude' => 34, 'longitude' => 73, 'reason' => 'Not a GPX observation'],
            'correction_approved_by' => 1, 'correction_approved_at' => '2026-10-25'];
        $this->assertNull($service->registered($transformer));
    }
}
