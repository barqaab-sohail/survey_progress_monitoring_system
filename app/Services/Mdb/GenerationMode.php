<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;

/** The project setting is frozen with each survey snapshot. Missing settings use full validation. */
class GenerationMode
{
    public static function simple(array|Configuration|null $configuration): bool
    {
        $settings = $configuration instanceof Configuration
            ? $configuration->settings
            : ($configuration['settings'] ?? []);

        return in_array($settings['simple_mdb_generation'] ?? false, [true, 1, '1'], true);
    }
}
