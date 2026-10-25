<?php

namespace App\Services\Mdb;

use RuntimeException;

/** WGS84 UTM forward projection, sixth-order Kruger series, in metres.
 * Formula: https://proj.org/en/stable/operations/projections/tmerc.html
 * Restricted to the explicit EPSG zone/hemisphere; no guessed CRS or datum shift.
 */
class Wgs84Utm
{
    public static function supports(int $epsg): bool
    {
        return ($epsg >= 32601 && $epsg <= 32660) || ($epsg >= 32701 && $epsg <= 32760);
    }

    public static function project(array $points, int $epsg): array
    {
        if (! self::supports($epsg)) {
            throw new RuntimeException('Projection failed: unsupported WGS84 UTM EPSG.');
        }
        $south = $epsg >= 32701;
        $zone = $epsg % 100;
        $west = $zone * 6 - 186;
        $central = $west + 3;
        $f = 1 / 298.257223563;
        $n = $f / (2 - $f);
        $e = sqrt($f * (2 - $f));
        $a = 6378137 / (1 + $n) * (1 + $n ** 2 / 4 + $n ** 4 / 64 + $n ** 6 / 256);
        $alpha = [
            $n / 2 - 2 * $n ** 2 / 3 + 5 * $n ** 3 / 16 + 41 * $n ** 4 / 180 - 127 * $n ** 5 / 288 + 7891 * $n ** 6 / 37800,
            13 * $n ** 2 / 48 - 3 * $n ** 3 / 5 + 557 * $n ** 4 / 1440 + 281 * $n ** 5 / 630 - 1983433 * $n ** 6 / 1935360,
            61 * $n ** 3 / 240 - 103 * $n ** 4 / 140 + 15061 * $n ** 5 / 26880 + 167603 * $n ** 6 / 181440,
            49561 * $n ** 4 / 161280 - 179 * $n ** 5 / 168 + 6601661 * $n ** 6 / 7257600,
            34729 * $n ** 5 / 80640 - 3418889 * $n ** 6 / 1995840,
            212378941 * $n ** 6 / 319334400,
        ];
        $result = [];
        foreach ($points as $point) {
            $lat = (float) ($point['latitude'] ?? $point['lat']);
            $lon = (float) ($point['longitude'] ?? $point['lon']);
            if (! is_finite($lat) || ! is_finite($lon) || $lon < $west || $lon > $west + 6
                || ($south ? ($lat < -80 || $lat > 0) : ($lat < 0 || $lat > 84))) {
                throw new RuntimeException('Projection failed: Coordinate lies outside the configured CRS area of use.');
            }
            $phi = deg2rad($lat);
            $lambda = deg2rad($lon - $central);
            $conformal = sinh(asinh(tan($phi)) - $e * atanh($e * sin($phi)));
            $xi0 = atan2($conformal, cos($lambda));
            $eta0 = asinh(sin($lambda) / hypot($conformal, cos($lambda)));
            $xi = $xi0;
            $eta = $eta0;
            foreach ($alpha as $index => $coefficient) {
                $j = 2 * ($index + 1);
                $xi += $coefficient * sin($j * $xi0) * cosh($j * $eta0);
                $eta += $coefficient * cos($j * $xi0) * sinh($j * $eta0);
            }
            $result[] = ['x' => 500000 + 0.9996 * $a * $eta, 'y' => ($south ? 10000000 : 0) + 0.9996 * $a * $xi];
        }

        return $result;
    }
}
