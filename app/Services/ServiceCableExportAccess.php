<?php

namespace App\Services;

use App\Models\User;

class ServiceCableExportAccess
{
    /** Full feeder exports contain other collectors' records, so require feeder-wide read authority. */
    public function allows(User $user): bool
    {
        return $user->canViewConsumerServiceCables()
            && (! $user->organization_id || $user->organization?->status->value === 'active')
            && ($user->hasAnyRole(['super_admin', 'project_manager', 'management_viewer'])
                || $user->checkPermissionTo('ServiceCables:ExportFeeder'));
    }
}
