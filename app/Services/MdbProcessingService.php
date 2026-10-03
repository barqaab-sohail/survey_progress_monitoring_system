<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Models\Feeder;
use App\Models\MdbProcessingAssignment;
use App\Models\MdbProcessingDailyEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MdbProcessingService
{
    public function __construct(private readonly AuditService $audit) {}

    public function assign(User $actor, Organization $organization, array $data): MdbProcessingAssignment
    {
        Gate::forUser($actor)->authorize('create', MdbProcessingAssignment::class);

        return DB::transaction(function () use ($actor, $organization, $data) {
            $feeder = Feeder::query()->lockForUpdate()->findOrFail($data['feeder_id']);
            $created = $feeder->mdbItems()->sum('mdb_files_created');
            $assigned = $feeder->processingAssignments()->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])->sum('assigned_quantity');
            $available = max($created - $assigned, 0);
            if ((int) $data['assigned_quantity'] > $available) {
                throw ValidationException::withMessages(['assigned_quantity' => "Only {$available} created MDB files are currently unassigned for Feeder {$feeder->feeder_code}."]);
            }

            $assignment = MdbProcessingAssignment::create([
                ...$data,
                'organization_id' => $organization->id,
                'assigned_by' => $actor->id,
                'drive_url' => $data['drive_url'] ?? $feeder->processing_drive_url ?? $feeder->mdb_drive_url,
                'status' => AssignmentStatus::Active,
            ]);
            $this->audit->record($actor, 'processing.assigned', $assignment, new: $assignment->toArray());

            return $assignment;
        });
    }

    public function recordProgress(User $actor, array $data): MdbProcessingDailyEntry
    {
        return DB::transaction(function () use ($actor, $data) {
            $entry = MdbProcessingDailyEntry::create([
                'entry_date' => $data['entry_date'],
                'organization_id' => $actor->organization_id,
                'entered_by' => $actor->id,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['items'] as $row) {
                $assignment = MdbProcessingAssignment::query()->lockForUpdate()->findOrFail($row['assignment_id']);
                if ($assignment->organization_id !== $actor->organization_id) {
                    abort(403, 'You do not have permission to view this processing assignment.');
                }
                Gate::forUser($actor)->authorize('recordProgress', $assignment);
                if ($assignment->status !== AssignmentStatus::Active) {
                    throw ValidationException::withMessages(['items' => 'This processing assignment is not active.']);
                }

                Feeder::query()->lockForUpdate()->findOrFail($assignment->feeder_id);
                $processed = $assignment->progressItems()->sum('mdb_processed');
                $remaining = max($assignment->assigned_quantity - $processed, 0);
                $created = $assignment->feeder->mdbItems()->sum('mdb_files_created');
                $feederProcessed = MdbProcessingAssignment::query()->where('feeder_id', $assignment->feeder_id)
                    ->withSum('progressItems', 'mdb_processed')->get()->sum('progress_items_sum_mdb_processed');
                $createdRemaining = max($created - $feederProcessed, 0);
                $maximum = min($remaining, $createdRemaining);
                if ((int) $row['mdb_processed'] > $maximum) {
                    throw ValidationException::withMessages(['items' => "Only {$maximum} MDB files remain in this processing assignment."]);
                }

                $entry->items()->create([
                    'mdb_processing_assignment_id' => $assignment->id,
                    'mdb_processed' => $row['mdb_processed'],
                    'output_drive_url' => $row['output_drive_url'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                ]);

                if ($processed + (int) $row['mdb_processed'] === $assignment->assigned_quantity) {
                    $assignment->update(['status' => AssignmentStatus::Completed]);
                }
            }

            $this->audit->record($actor, 'processing.progress_recorded', $entry, new: $entry->load('items')->toArray());

            return $entry;
        });
    }
}
