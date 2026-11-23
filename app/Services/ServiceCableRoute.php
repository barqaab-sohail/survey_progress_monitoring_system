<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Ordered, observed route coordinates; never a pole-to-meter distance estimate. */
class ServiceCableRoute
{
    public function rules(string $prefix = ''): array
    {
        $key = $prefix === '' ? 'route_points' : $prefix.'.route_points';

        return [
            $key => 'sometimes|nullable|array|list|max:500',
            $key.'.*' => 'array:latitude,longitude,waypoint',
            $key.'.*.latitude' => 'required|numeric|between:-90,90',
            $key.'.*.longitude' => 'required|numeric|between:-180,180',
            $key.'.*.waypoint' => 'nullable|string|max:100',
        ];
    }

    public function normalize(array $cable, string $prefix = 'service_cable'): array
    {
        $validator = Validator::make($cable, $this->rules());
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) $errors[$prefix.'.'.$key] = $messages;
            throw ValidationException::withMessages($errors);
        }
        // A client-supplied total is never authoritative, including when no route exists.
        unset($cable['route_length_m']);
        if (! array_key_exists('route_points', $cable)) return $cable;
        $points = $cable['route_points'] ?? [];
        if ($points === []) {
            $cable['route_points'] = [];
            $cable['route_length_m'] = null;
            return $cable;
        }
        if (count($points) < 2) {
            throw ValidationException::withMessages([$prefix.'.route_points' => 'Record the pole, meter and every bend in route order (at least two points).']);
        }
        $total = 0.0;
        foreach ($points as $index => &$point) {
            $point['latitude'] = (float) $point['latitude'];
            $point['longitude'] = (float) $point['longitude'];
            if ($point['latitude'] === 0.0 && $point['longitude'] === 0.0) {
                throw ValidationException::withMessages([$prefix.'.route_points.'.$index => 'Record a valid surveyed coordinate instead of latitude 0, longitude 0.']);
            }
            if ($index === 0) continue;
            $segment = $this->distance($points[$index - 1], $point);
            if ($segment < 0.001) {
                throw ValidationException::withMessages([$prefix.'.route_points.'.$index => 'Consecutive route points must have different coordinates.']);
            }
            $total += $segment;
        }
        unset($point);
        if ($total > 100000) {
            throw ValidationException::withMessages([$prefix.'.route_points' => 'A service cable route cannot exceed 100,000 metres. Check the recorded route coordinates.']);
        }
        $cable['route_points'] = $points;
        $cable['route_length_m'] = round($total, 3);

        return $cable;
    }

    public function normalizeMany(array $cables): array
    {
        foreach ($cables as $index => $cable) {
            $cables[$index] = $this->normalize($cable, 'service_cables.'.$index);
        }

        return $cables;
    }

    private function distance(array $from, array $to): float
    {
        $lat1 = deg2rad($from['latitude']);
        $lat2 = deg2rad($to['latitude']);
        $a = sin(($lat2 - $lat1) / 2) ** 2
            + cos($lat1) * cos($lat2) * sin(deg2rad($to['longitude'] - $from['longitude']) / 2) ** 2;

        return 6371008.8 * 2 * asin(sqrt(max(0.0, min(1.0, $a))));
    }
}
