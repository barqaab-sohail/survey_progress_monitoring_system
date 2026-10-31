<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\Payload;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(User $actor, string $action, Model $record, array $old = [], array $new = [], ?string $reason = null): ?AuditLog
    {
        if ($record instanceof AuditLog) {
            return null; // Audit maintenance must never recursively audit itself.
        }
        $policy = new Payload;
        // Model::toArray() can include arbitrary loaded relationships. Business child collections
        // explicitly used for change traceability remain subject to the same diff/budget policy.
        foreach (array_keys($record->getRelations()) as $relation) {
            if (isset($old['schema_version'], $old['batch']) || isset($new['schema_version'], $new['batch'])) {
                break; // Explicit batch snapshots are diffed; these are not accidental model relations.
            }
            if (! in_array($relation, ['items', 'consumers', 'pv_records', 'entry_rows', 'sections'], true)
                && ! array_key_exists($relation, $record->getAttributes())) {
                unset($old[$relation], $new[$relation]);
            }
        }
        if ($old !== [] && $new !== []) {
            [$before, $after] = $policy->diff($old, $new);
            if (array_diff_key($before + $after, array_flip(['revision'])) === []) {
                return null;
            }
        }
        [$old, $new] = $policy->prepare($old, $new);

        return AuditLog::create([
            'user_id' => $actor->id,
            'organization_id' => $actor->organization_id,
            'action' => $action,
            'auditable_type' => $record::class,
            'auditable_id' => $record->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $policy->text($reason),
            'ip_address' => request()?->ip(),
            'user_agent' => $policy->text(request()?->userAgent(), 512),
        ]);
    }
}
