<?php

namespace App\Services\Audit;

use App\Services\Backup\Drive;
use App\Services\Backup\Workspace;
use Carbon\CarbonImmutable;
use RuntimeException;

class BackupGuard
{
    public function verify(int $throughId): void
    {
        $workspace = new Workspace;
        $receipt = $workspace->read('state')['latest_zip'] ?? [];
        if (empty($receipt['uploaded_at']) || empty($receipt['file_id']) || empty($receipt['md5'])
            || ($receipt['database_fingerprint'] ?? '') !== (new Storage)->identity()
            || (int) ($receipt['audit_through_id'] ?? -1) < $throughId
            || CarbonImmutable::parse($receipt['uploaded_at'])->isFuture()
            || CarbonImmutable::parse($receipt['uploaded_at'])->lt(now()->subHours(config('audit.backup_max_hours')))) {
            throw new RuntimeException('A recent verified backup of this database covering these audit IDs is required. Run backup:run and verify recovery before cleanup.');
        }
        $drive = app(Drive::class);
        $drive->useWorkspace($workspace);
        $drive->assertMetadata($receipt, $drive->metadata($receipt['file_id']) ?? []);
    }
}
