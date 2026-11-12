<?php

namespace App\Services\Mdb;

/** Exact library identifiers extracted read-only from the approved warehouse. */
class ApprovedWarehouse
{
    public static function data(): array
    {
        static $data;
        return $data ??= json_decode(file_get_contents(config_path('approved_warehouse.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function conductors(): array
    {
        return self::data()['conductors'];
    }

    public static function capacities(): array
    {
        return array_values(array_unique(array_column(array_filter(self::data()['transformers'], fn ($t) => (float) $t['primary_kv'] === 11.0 && (float) $t['secondary_kv'] === 0.415), 'capacity_kva')));
    }

    public static function transformerMatches(string $name, mixed $capacity): bool
    {
        foreach (self::data()['transformers'] as $type) {
            if ($type['name'] === $name && (float) $type['capacity_kva'] === (float) $capacity
                && (float) $type['primary_kv'] === 11.0 && (float) $type['secondary_kv'] === 0.415) return true;
        }
        return false;
    }
}
