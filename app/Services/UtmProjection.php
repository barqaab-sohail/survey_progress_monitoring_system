<?php

namespace App\Services;

use InvalidArgumentException;

class UtmProjection
{
    /** WGS84 Transverse Mercator series, metres, northern hemisphere. */
    public function project(float $latitude, float $longitude, int $zone): array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < 0 || $latitude > 84 || $zone < 1 || $zone > 60
            || abs($longitude - ($zone * 6 - 183)) > 6) {
            throw new InvalidArgumentException('Coordinates must be in the northern UTM hemisphere and within 6 degrees of the chosen zone central meridian.');
        }
        $a = 6378137.0;
        $e2 = 0.0066943799901413165;
        $ep2 = $e2 / (1 - $e2);
        $phi = deg2rad($latitude);
        $lambda = deg2rad($longitude);
        $lambda0 = deg2rad($zone * 6 - 183);
        $n = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $t = tan($phi) ** 2;
        $c = $ep2 * cos($phi) ** 2;
        $aa = cos($phi) * ($lambda - $lambda0);
        $m = $a * ((1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $phi
            - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $phi)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $phi)
            - 35 * $e2 ** 3 / 3072 * sin(6 * $phi));
        $x = 500000 + 0.9996 * $n * ($aa + (1 - $t + $c) * $aa ** 3 / 6 + (5 - 18 * $t + $t ** 2 + 72 * $c - 58 * $ep2) * $aa ** 5 / 120);
        $y = 0.9996 * ($m + $n * tan($phi) * ($aa ** 2 / 2 + (5 - $t + 9 * $c + 4 * $c ** 2) * $aa ** 4 / 24
            + (61 - 58 * $t + $t ** 2 + 600 * $c - 330 * $ep2) * $aa ** 6 / 720));

        return ['X' => $x, 'Y' => $y];
    }
}
