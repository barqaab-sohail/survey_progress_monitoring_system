<?php

namespace App\Services\Audit;

use Illuminate\Contracts\Support\Arrayable;

/** One policy for new events and optional, explicit legacy compaction. */
class Payload
{
    private array $omitted = [];

    private int $visited = 0;

    public function prepare(array $old, array $new, bool $changes = true): array
    {
        $this->omitted = [];
        $this->visited = 0;
        $outcome = $new['outcome'] ?? $new['status'] ?? null;
        $previous = $new['_audit'] ?? [];
        if ($changes && $old !== [] && $new !== []) {
            [$old, $new] = $this->diff($old, $new);
        }
        // Initial imports/mobile uploads are datasets, not thousands of field-level events.
        // Subsequent updates still retain their relevant old/new changed fields.
        if ($old === []) {
            foreach (['rows', 'solar', 'waypoints', 'transformers', 'sources', 'generation_inputs'] as $collection) {
                if (isset($new[$collection]) && is_array($new[$collection]) && ! $this->isSummary($new[$collection])) {
                    $new[$collection] = $this->summary($new[$collection], 'new.'.$collection, 'initial dataset; retained on affected business record');
                }
            }
        }
        $old = $this->clean($old, 'old');
        $new = $this->clean($new, 'new');
        $budget = max(2048, (int) config('audit.payload_bytes'));
        // Replace the largest top-level detail first; keep field names and exact small values.
        while (strlen(json_encode([$old, $new], JSON_THROW_ON_ERROR)) > $budget - 768) {
            $largest = null;
            $bytes = 0;
            foreach (['old' => $old, 'new' => $new] as $side => $values) {
                foreach ($values as $key => $value) {
                    $size = strlen(json_encode($value, JSON_THROW_ON_ERROR));
                    if ($size > $bytes) {
                        $largest = [$side, $key];
                        $bytes = $size;
                    }
                }
            }
            if (! $largest || $bytes < 200) {
                // Many tiny fields: keep count/hash and explicit omission metadata.
                $old = $old ? ['_details' => $this->summary($old, 'old', 'payload budget')] : [];
                $new = $new ? ['_details' => $this->summary($new, 'new', 'payload budget')] : [];
                break;
            }
            [$side, $key] = $largest;
            if ($side === 'old') {
                $old[$key] = $this->summary($old[$key], 'old.'.$key, 'payload budget');
            } else {
                $new[$key] = $this->summary($new[$key], 'new.'.$key, 'payload budget');
            }
        }
        if (is_array($previous) && ($previous['policy'] ?? null) === 1 && ($previous['details_omitted'] ?? false)
            && is_array($previous['paths'] ?? null)) {
            $paths = array_filter(array_slice($previous['paths'], 0, 8), fn ($p) => is_string($p) && strlen($p) <= 64);
            $this->omitted = array_fill_keys($paths, true) + $this->omitted;
        }
        if ($this->omitted) {
            $new['_audit'] = ['details_omitted' => true, 'omissions' => max(count($this->omitted), min(100000, (int) ($previous['omissions'] ?? 0))),
                'paths' => array_map(fn ($p) => substr(preg_replace('/[^A-Za-z0-9_.\[\]-]/', '?', $p), 0, 48), array_slice(array_keys($this->omitted), 0, 8)), 'policy' => 1];
            if (is_string($outcome) && strlen(json_encode($outcome)) <= 80) {
                $new['_audit']['outcome'] = $this->text($outcome);
            }
        }

        return [$old, $new];
    }

    public function diff(array $old, array $new): array
    {
        $before = $after = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (in_array($key, ['created_at', 'updated_at', 'save_uuid', 'save_hash'], true)) {
                continue;
            }
            $hasOld = array_key_exists($key, $old);
            $hasNew = array_key_exists($key, $new);
            if ($hasOld && $hasNew && $old[$key] === $new[$key]) {
                continue;
            }
            if ($hasOld && $hasNew && is_array($old[$key]) && is_array($new[$key])) {
                [$a, $b] = $this->diff($old[$key], $new[$key]);
                if ($a !== [] || $b !== []) {
                    $before[$key] = $a;
                    $after[$key] = $b;
                }
            } else {
                $before[$key] = $hasOld ? $old[$key] : null;
                $after[$key] = $hasNew ? $new[$key] : null;
            }
        }

        return [$before, $after];
    }

    private function clean(mixed $value, string $path, int $depth = 0): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_object($value)) {
            return ['omitted' => 'object'];
        }
        if (is_array($value)) {
            if ($this->isSummary($value)) {
                return $value; // Compaction and viewer rendering must not re-hash an existing summary.
            }
            if ($depth >= 12 || count($value) > 100 || $this->visited > 2000) {
                return $this->summary($value, $path, 'large collection');
            }
            $result = [];
            foreach ($value as $key => $item) {
                $this->visited++;
                $next = $path.'.'.$key;
                if (strlen((string) $key) > 120) {
                    $this->omitted[$path.'.[oversized field name]'] = true;
                    $result['_oversized_field_names_omitted'] = true;

                    continue;
                }
                $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
                if (preg_match('/password|passwd|secret|token|authorization|cookie|credential|privatekey|apikey|accesskey|base64|filecontent|rawcontent|binary|^contents?$/', $normalizedKey)) {
                    $result[$key] = ['omitted' => 'sensitive or file content'];
                    $this->omitted[$next] = true;
                } elseif (in_array($key, ['source_data', 'reference_snapshot', 'payload', 'dataset', 'relationships', 'roles', 'permissions', 'pivot'], true)) {
                    $result[$key] = $this->summary($item, $next, 'dataset');
                } else {
                    $result[$key] = $this->clean($item, $next, $depth + 1);
                }
            }

            return $result;
        }
        if (is_string($value)) {
            // Neither long binary/text content nor embedded data URLs belong in an audit trail.
            if (strlen($value) > 2048 || preg_match('~data:[^;]+;base64,|%PDF-|^[A-Za-z0-9+/]{256,}={0,2}$~', $value)) {
                return $this->summary($value, $path, 'large text or file content');
            }

            return $this->text($value);
        }

        return $value;
    }

    private function summary(mixed $value, string $path, string $why): array
    {
        $this->omitted[$path] = true;
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return ['omitted' => $why, 'bytes' => strlen($encoded), 'count' => is_array($value) ? count($value) : null,
            'sha256' => hash('sha256', $encoded)];
    }

    private function isSummary(array $value): bool
    {
        return count($value) === 4 && isset($value['bytes'], $value['sha256'], $value['omitted'])
            && array_key_exists('count', $value) && is_int($value['bytes'])
            && (is_int($value['count']) || $value['count'] === null)
            && is_string($value['sha256']) && preg_match('/^[a-f0-9]{64}$/D', $value['sha256'])
            && in_array($value['omitted'], ['large collection', 'large text or file content', 'dataset',
                'payload budget', 'initial dataset; retained on affected business record'], true);
    }

    public function text(?string $value, int $limit = 2048): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = preg_replace('~(Bearer\s+)[^\s,;]+~i', '$1[REDACTED]', $value);
        $keys = '(?:password|passwd|secret|token|access_token|refresh_token|api_key|client_secret)';
        // Quoted credentials can contain spaces and punctuation; redact the whole quoted value.
        $value = preg_replace('~('.$keys.'["\x27]?\s*[=:]\s*)(["\x27])(?:(?!\2).)*\2~is', '$1[REDACTED]', $value);
        $value = preg_replace('~('.$keys.'["\x27]?\s*[=:]\s*)[^\r\n&,;]+~i', '$1[REDACTED]', $value);
        if (strlen($value) > $limit) {
            return mb_strcut($value, 0, $limit - 40, 'UTF-8').' [additional text omitted]';
        }

        return $value;
    }
}
