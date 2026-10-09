<?php

namespace App\Services\Mdb;

use RuntimeException;

class ReadbackVerifier
{
    public function verify(string $path, array $payload, array $result): array
    {
        if (! is_file($path) || filesize($path) < 4096) {
            throw new RuntimeException('MDB worker did not produce a database artifact.');
        }
        $handle = fopen($path, 'rb');
        try {
            $header = fread($handle, 32);
        } finally {
            fclose($handle);
        }
        if (substr($header, 4, 16) !== "Standard Jet DB\0") {
            throw new RuntimeException('Output is not a Microsoft Access Jet MDB.');
        }
        $readback = $result['readback'] ?? [];
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (! hash_equals(hash('sha256', $encodedPayload), (string) ($result['payload_sha256'] ?? ''))) {
            throw new RuntimeException('MDB readback is not bound to the exact approved export payload.');
        }
        foreach (['readable', 'schema_verified', 'references_verified', 'mapped_values_verified'] as $flag) {
            if (($readback[$flag] ?? false) !== true) {
                throw new RuntimeException('MDB readback verification is incomplete: '.$flag.'.');
            }
        }
        foreach ($payload['tables'] as $table => $rows) {
            if (($readback['counts'][$table] ?? -1) !== count($rows)) {
                throw new RuntimeException('MDB readback count differs for '.$table.'.');
            }
        }
        if (! hash_equals($payload['template']['sha256'], (string) ($readback['template_sha256'] ?? '')) ||
            ! hash_equals($payload['revision']['sha256'], (string) ($readback['revision_sha256'] ?? '')) ||
            ! hash_equals(hash_file('sha256', $path), (string) ($result['output_sha256'] ?? ''))) {
            throw new RuntimeException('MDB readback identity or output SHA-256 differs.');
        }

        return $readback + ['synergee_validation' => 'pending'];
    }
}
