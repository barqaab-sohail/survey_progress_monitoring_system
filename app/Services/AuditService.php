<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(User $actor, string $action, Model $record, array $old = [], array $new = [], ?string $reason = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor->id,
            'organization_id' => $actor->organization_id,
            'action' => $action,
            'auditable_type' => $record::class,
            'auditable_id' => $record->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
