<?php

namespace App\Services\Mdb;

use RuntimeException;

/** The approved export template schema, including the user-approved meter demand extension. */
class ApprovedSchema
{
    public static function fingerprint(): string
    {
        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['reference_count'] ?? null) !== 15 ||
            count($manifest['schema']['tables'] ?? []) !== 51 || count($manifest['schema']['relationships'] ?? []) !== 42 ||
            ! preg_match('/^[a-f0-9]{64}$/', $manifest['schema_sha256'] ?? '')) {
            throw new RuntimeException('The approved MDB schema manifest is invalid.');
        }

        return $manifest['schema_sha256'];
    }

    public static function acceptsTransferFingerprint(string $fingerprint): bool
    {
        $current = self::fingerprint();
        if (hash_equals($current, $fingerprint)) {
            return true;
        }

        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $extension = $manifest['schema_extension'] ?? [];
        $legacy = $extension['legacy_schema_sha256'] ?? '';

        return is_string($legacy) && preg_match('/^[a-f0-9]{64}$/D', $legacy) === 1
            && (int) ($extension['legacy_table_count'] ?? 0) === 50
            && (int) ($extension['legacy_relationship_count'] ?? 0) === 41
            && hash_equals($legacy, $fingerprint)
            && count(array_filter($manifest['references'] ?? [], fn ($reference) => ($reference['schema_sha256'] ?? null) === $legacy)) === 15;
    }

    public static function assertReadback(array $readback): void
    {
        if (! hash_equals(self::fingerprint(), (string) ($readback['schema_sha256'] ?? '')) ||
            ($readback['schema_table_count'] ?? null) !== 51 || ($readback['schema_relationship_count'] ?? null) !== 42) {
            throw new RuntimeException('MDB readback schema differs from the approved export template.');
        }
    }
}
