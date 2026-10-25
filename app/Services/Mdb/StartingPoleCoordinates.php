<?php

namespace App\Services\Mdb;

use App\Models\Transformer;

/** Freeze the coordinate choice at new transformer selection; never query live GIS records during export. */
class StartingPoleCoordinates
{
    public const REGISTERED = 'registered_transformer';

    public function capture(?Transformer $registered): array
    {
        $choice = ['version' => 1, 'source' => SurveyWaypoint::FIRST_PAIR_COORDINATE_SOURCE];
        if (! $registered) {
            return $choice;
        }
        // Read named database columns, never positional GeoJSON/KML coordinates or request values.
        $point = ['latitude' => $registered->getRawOriginal('latitude'), 'longitude' => $registered->getRawOriginal('longitude')];
        if (! $this->valid($point)) {
            return $choice;
        }
        // Imported PointX is longitude; PointY is latitude. Reject contradictory source evidence,
        // including swapped values that individually still fit the WGS84 ranges. Never auto-swap.
        $attributes = $registered->raw_attributes ?? [];
        if (! is_array($attributes)) {
            return $choice;
        }
        foreach ($attributes as $key => $value) {
            $column = match (strtolower(preg_replace('/[^a-z0-9]/i', '', $key))) {
                'pointx', 'longitude' => 'longitude', 'pointy', 'latitude' => 'latitude', default => null,
            };
            if ($column && $value !== null
                && (! is_scalar($value) || (trim((string) $value) !== ''
                    && (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value - (float) $point[$column]) > 0.001)))) {
                return $choice;
            }
        }
        $altitude = $registered->getRawOriginal('altitude');

        return ['version' => 1, 'source' => self::REGISTERED, 'registered_transformer_id' => $registered->id,
            'feeder_id' => $registered->feeder_id, 'transformer_code' => $registered->transformer_code,
            'latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude'],
            'elevation' => is_numeric($altitude) && is_finite((float) $altitude) ? (float) $altitude : null,
            'record_updated_at' => $registered->getRawOriginal('updated_at')];
    }

    private function valid(array $point): bool
    {
        return (new SurveyWaypoint)->validCoordinates(array_intersect_key($point, array_flip(['latitude', 'longitude', 'elevation'])))
            && ((float) $point['latitude'] !== 0.0 || (float) $point['longitude'] !== 0.0);
    }

    public function registered(array $transformer): ?array
    {
        $choice = $transformer['header']['starting_pole_coordinates'] ?? [];
        if (($choice['version'] ?? null) !== 1 || ($choice['source'] ?? null) !== self::REGISTERED
            || empty($choice['registered_transformer_id'])
            || ($choice['registered_transformer_id'] ?? null) != ($transformer['header']['registered_transformer_id'] ?? null)
            || ! $this->valid($choice)) {
            return null;
        }

        return $choice;
    }
}
