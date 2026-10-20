<?php

namespace App\Services\Mdb;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProjectionService
{
    public const LENGTH_METHOD = 'gpx-elevation-v1';

    public function project(float $latitude, float $longitude, int $epsg): array
    {
        return $this->projectMany([['latitude' => $latitude, 'longitude' => $longitude]], $epsg)[0];
    }

    public function projectMany(array $points, int $epsg): array
    {
        if ($epsg < 1 || $epsg > 999999) {
            throw new InvalidArgumentException('An explicit valid projected EPSG code is required.');
        }
        foreach ($points as $point) {
            $lat = $point['latitude'] ?? $point['lat'] ?? null;
            $lon = $point['longitude'] ?? $point['lon'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lon) || ! is_finite((float) $lat) || ! is_finite((float) $lon)
                || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                throw new InvalidArgumentException('WGS84 coordinates are outside valid ranges.');
            }
        }
        $worker = new Process([(string) config('mdb_workflow.projection.python', 'python'), base_path('scripts/mdb/project_coordinates.py')]);
        $worker->setTimeout((float) config('mdb_workflow.projection.timeout', 30));
        $worker->setInput(json_encode(['epsg' => $epsg, 'points' => $points], JSON_THROW_ON_ERROR));
        try {
            $worker->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Projection worker unavailable: install Python and pyproj, and configure MDB_PROJECTION_PYTHON.', 0, $exception);
        }
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('Projection failed: '.mb_substr(trim($worker->getErrorOutput()), 0, 1000));
        }
        $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result['points'] ?? null) || count($result['points']) !== count($points)) {
            throw new RuntimeException('Projection worker returned an invalid response.');
        }

        return $result['points'];
    }

    /** Geometry includes endpoints. Missing elevations retain a horizontal estimate. */
    public function length(array $points, int $epsg): float
    {
        return $this->lengthDetails($points, $epsg)['length_m'];
    }

    public function lengthDetails(array $points, int $epsg): array
    {
        if (count($points) < 2) {
            throw new InvalidArgumentException('Length requires at least two geometry points.');
        }
        return self::lengthFromProjected($points, $this->projectMany($points, $epsg));
    }

    public static function elevationStatus(array $points): string
    {
        $missing = false;
        foreach ($points as $point) {
            $value = $point['elevation'] ?? null;
            if ($value === null || $value === '') {
                $missing = true;
            } elseif (! is_numeric($value) || ! is_finite((float) $value)) {
                return 'horizontal_invalid_elevation';
            }
        }

        return $missing ? 'horizontal_missing_elevation' : 'elevation_adjusted';
    }

    /** Reuse projected coordinates so validation and export apply identical maths. */
    public static function lengthFromProjected(array $points, array $projected): array
    {
        if (count($points) < 2) {
            throw new InvalidArgumentException('Length requires at least two geometry points.');
        }
        if (count($points) !== count($projected)) {
            throw new InvalidArgumentException('Each geometry point needs projected coordinates.');
        }
        $points = array_values($points);
        $projected = array_values($projected);
        $status = self::elevationStatus($points);
        $horizontalLength = $length = 0.0;
        foreach ($projected as $index => $point) {
            if ($index > 0) {
                $previous = $projected[$index - 1];
                $horizontal = hypot($point['x'] - $previous['x'], $point['y'] - $previous['y']);
                $horizontalLength += $horizontal;
                $length += $status === 'elevation_adjusted'
                    ? hypot($horizontal, (float) $points[$index]['elevation'] - (float) $points[$index - 1]['elevation'])
                    : $horizontal;
            }
        }

        return ['length_m' => $length, 'horizontal_length_m' => $horizontalLength, 'elevation_status' => $status];
    }
}
