<?php

namespace App\Services\Mdb;

use RuntimeException;

/** The database structure shared by all fifteen approved reference MDBs. */
class ApprovedSchema
{
    public static function fingerprint(): string
    {
        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['reference_count'] ?? null) !== 15 ||
            count($manifest['schema']['tables'] ?? []) !== 50 || count($manifest['schema']['relationships'] ?? []) !== 41 ||
            ! preg_match('/^[a-f0-9]{64}$/', $manifest['schema_sha256'] ?? '')) {
            throw new RuntimeException('The approved MDB schema manifest is invalid.');
        }

        return $manifest['schema_sha256'];
    }

    public static function assertReadback(array $readback): void
    {
        if (! hash_equals(self::fingerprint(), (string) ($readback['schema_sha256'] ?? '')) ||
            ($readback['schema_table_count'] ?? null) !== 50 || ($readback['schema_relationship_count'] ?? null) !== 41) {
            throw new RuntimeException('MDB readback schema differs from the 15 approved sample databases.');
        }
    }
}
