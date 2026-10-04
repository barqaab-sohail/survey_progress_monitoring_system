<?php

namespace App\Services;

use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\MdbDailyEntry;
use App\Models\MdbDailyEntryItem;
use App\Models\MdbTeam;
use App\Models\MdbVerificationHistory;
use App\Models\User;
use App\Policies\MdbDailyEntryItemPolicy;
use App\Policies\MdbDailyEntryPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MdbCreationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function update(User $actor, MdbDailyEntry $entry, array $data): void
    {
        DB::transaction(function () use ($actor, $entry, $data) {
            $entry = MdbDailyEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $feederIds = $entry->items()->pluck('feeder_id');
            $feeders = Feeder::whereIn('id', $feederIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = $entry->items()->orderBy('id')->lockForUpdate()->get()->keyBy('feeder_id');
            Gate::forUser($actor)->authorize('update', $entry);
            abort_unless(app(MdbDailyEntryPolicy::class)->update($actor, $entry), 403, 'MDB editing is locked once verification begins.');
            if (count($data['items']) !== $items->count()
                || collect($data['items'])->pluck('feeder_id')->map(fn ($id) => (int) $id)->sort()->values()->all() !== $items->keys()->map(fn ($id) => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'Editing must retain the original feeder rows.']);
            }
            $old = $entry->setRelation('items', $items->values())->toArray();
            foreach ($data['items'] as $row) {
                $item = $items->get($row['feeder_id']);
                $feeder = $feeders->get($row['feeder_id']);
                $verified = $this->verifiedSurveyQuantity($feeder);
                $created = $this->reservedMdbQuantity($feeder, $item->id);
                $available = max($verified - $created, 0);
                if ((int) $row['mdb_files_created'] > $available) {
                    throw ValidationException::withMessages(['items' => "Only {$available} verified transformers are currently available for MDB creation for Feeder {$feeder->feeder_code}."]);
                }
                $item->update([
                    'mdb_files_created' => $row['mdb_files_created'],
                    'drive_url' => $row['drive_url'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                ]);
            }
            $entry->update(['entry_date' => $data['entry_date'], 'remarks' => $data['remarks'] ?? null]);
            $this->audit->record($actor, 'mdb.updated', $entry, $old, $entry->fresh('items')->toArray());
        }, 3);
    }

    public function create(User $actor, ?MdbTeam $team, array $data): MdbDailyEntry
    {
        Gate::forUser($actor)->authorize('create', MdbDailyEntry::class);
        abort_unless(app(MdbDailyEntryPolicy::class)->create($actor), 403);

        return DB::transaction(function () use ($actor, $team, $data) {
            $entry = MdbDailyEntry::create([
                'entry_date' => $data['entry_date'],
                'mdb_team_id' => $team?->id,
                'entered_by' => $actor->id,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $feeders = Feeder::whereIn('id', collect($data['items'])->pluck('feeder_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($data['items'] as $row) {
                $feeder = $feeders->get($row['feeder_id']);
                abort_unless($feeder, 404);
                $verified = $this->verifiedSurveyQuantity($feeder);
                $created = $this->reservedMdbQuantity($feeder);
                $available = max($verified - $created, 0);
                if ((int) $row['mdb_files_created'] > $available) {
                    throw ValidationException::withMessages(['items' => "Only {$available} verified transformers are currently available for MDB creation for Feeder {$feeder->feeder_code}."]);
                }

                $entry->items()->create([
                    'feeder_id' => $feeder->id,
                    'mdb_files_created' => $row['mdb_files_created'],
                    'drive_url' => $row['drive_url'] ?? $feeder->mdb_drive_url,
                    'remarks' => $row['remarks'] ?? null,
                    'status' => SurveyItemStatus::Submitted,
                ]);
            }

            $this->audit->record($actor, 'mdb.created', $entry, new: $entry->load('items')->toArray());

            return $entry;
        }, 3);
    }

    public function verify(User $actor, MdbDailyEntryItem $item): void
    {
        DB::transaction(function () use ($actor, $item) {
            $entry = MdbDailyEntry::query()->lockForUpdate()->findOrFail($item->mdb_daily_entry_id);
            $item = MdbDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->setRelation('entry', $entry);
            $this->authorizeReview($actor, $item, 'verify');

            $old = $item->toArray();
            $item->update([
                'status' => SurveyItemStatus::Verified,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'return_reason' => null,
            ]);
            $this->history($actor, $item, 'verified');
            $this->audit->record($actor, 'mdb.verified', $item, $old, $item->fresh()->toArray());
        });
    }

    public function returnForCorrection(User $actor, MdbDailyEntryItem $item, string $reason): void
    {
        DB::transaction(function () use ($actor, $item, $reason) {
            $entry = MdbDailyEntry::query()->lockForUpdate()->findOrFail($item->mdb_daily_entry_id);
            $item = MdbDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->setRelation('entry', $entry);
            $this->authorizeReview($actor, $item, 'return');

            $old = $item->toArray();
            $item->update([
                'status' => SurveyItemStatus::Returned,
                'return_reason' => $reason,
                'verified_by' => null,
                'verified_at' => null,
            ]);
            $this->history($actor, $item, 'returned', $reason);
            $this->audit->record($actor, 'mdb.returned', $item, $old, $item->fresh()->toArray(), $reason);
        });
    }

    public function resubmit(User $actor, MdbDailyEntryItem $item, array $data): void
    {
        DB::transaction(function () use ($actor, $item, $data) {
            $entry = MdbDailyEntry::query()->lockForUpdate()->findOrFail($item->mdb_daily_entry_id);
            $feederId = $entry->items()->whereKey($item->id)->value('feeder_id');
            $feeder = Feeder::query()->lockForUpdate()->findOrFail($feederId);
            $item = MdbDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->setRelation('entry', $entry);
            abort_unless(app(MdbDailyEntryItemPolicy::class)->update($actor, $item), 403, 'Only the MDB author or an administrator may correct a returned item.');
            Gate::forUser($actor)->authorize('update', $item);

            $verified = $this->verifiedSurveyQuantity($feeder);
            // Every submission reserves capacity while it is being reviewed or corrected.
            $created = $this->reservedMdbQuantity($feeder, $item->id);
            $available = max($verified - $created, 0);
            if ((int) $data['mdb_files_created'] > $available) {
                throw ValidationException::withMessages(['mdb_files_created' => "Only {$available} verified transformers are currently available for MDB creation for Feeder {$feeder->feeder_code}."]);
            }

            $old = $item->toArray();
            $item->update([
                'mdb_files_created' => $data['mdb_files_created'],
                'drive_url' => $data['drive_url'] ?? $item->drive_url,
                'remarks' => $data['remarks'] ?? null,
                'status' => SurveyItemStatus::Submitted,
                'return_reason' => null,
                'verified_by' => null,
                'verified_at' => null,
                'resubmitted_at' => now(),
            ]);
            $this->history($actor, $item, 'resubmitted');
            $this->audit->record($actor, 'mdb.resubmitted', $item, $old, $item->fresh()->toArray());
        }, 3);
    }

    private function verifiedSurveyQuantity(Feeder $feeder): int
    {
        // Locking reads see the latest committed quantities under MySQL repeatable read.
        return (int) $feeder->surveyItems()->where('status', SurveyItemStatus::Verified->value)
            ->orderBy('id')->lockForUpdate()->get(['id', 'transformers_surveyed'])->sum('transformers_surveyed');
    }

    private function reservedMdbQuantity(Feeder $feeder, ?int $exceptItemId = null): int
    {
        return (int) $feeder->mdbItems()->when($exceptItemId, fn ($query) => $query->whereKeyNot($exceptItemId))
            ->orderBy('id')->lockForUpdate()->get(['id', 'mdb_files_created'])->sum('mdb_files_created');
    }

    private function authorizeReview(User $actor, MdbDailyEntryItem $item, string $ability): void
    {
        $policy = app(MdbDailyEntryItemPolicy::class);
        // Business rules must also apply when the administrator Gate bypass is active.
        abort_unless($policy->verifyAny($actor), 403, 'Only an active third-party MDB reviewer or administrator may review MDB files.');
        abort_if((int) $item->entry->entered_by === (int) $actor->id, 403, 'MDB authors cannot verify or return their own submissions.');
        if ($item->status !== SurveyItemStatus::Submitted) {
            throw ValidationException::withMessages(['status' => 'This MDB entry is no longer pending verification.']);
        }
        abort_unless($policy->{$ability}($actor, $item), 403);
        Gate::forUser($actor)->authorize($ability, $item);
    }

    private function history(User $actor, MdbDailyEntryItem $item, string $action, ?string $comment = null): void
    {
        MdbVerificationHistory::create([
            'mdb_daily_entry_item_id' => $item->id,
            'action' => $action,
            'comment' => $comment,
            'acted_by' => $actor->id,
            'quantity_snapshot' => $item->mdb_files_created,
            'acted_at' => now(),
        ]);
    }
}
