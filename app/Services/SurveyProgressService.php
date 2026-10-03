<?php

namespace App\Services;

use App\Enums\SurveyEntryStatus;
use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyDailyEntryItem;
use App\Models\SurveyTeam;
use App\Models\SurveyVerificationHistory;
use App\Models\User;
use App\Notifications\SurveyReturnedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SurveyProgressService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(User $actor, SurveyTeam $team, array $data): SurveyDailyEntry
    {
        Gate::forUser($actor)->authorize('create', SurveyDailyEntry::class);

        return DB::transaction(function () use ($actor, $team, $data) {
            $entry = SurveyDailyEntry::create([
                'entry_date' => $data['entry_date'],
                'survey_team_id' => $team->id,
                'entered_by' => $actor->id,
                'status' => SurveyEntryStatus::Submitted,
                'remarks' => $data['remarks'] ?? null,
                'submitted_at' => now(),
            ]);

            foreach ($data['items'] as $row) {
                $feeder = Feeder::query()->lockForUpdate()->findOrFail($row['feeder_id']);
                if ($feeder->baseline_pending || $feeder->total_transformers < 1) {
                    throw ValidationException::withMessages(['items' => "Feeder {$feeder->feeder_code} cannot receive survey progress until its transformer baseline is verified."]);
                }
                $assigned = $feeder->assignments()->where('survey_team_id', $team->id)->where('status', 'active')->exists();
                if (! $assigned) {
                    throw ValidationException::withMessages(['items' => "Feeder {$feeder->feeder_code} is not assigned to your survey team."]);
                }

                $reported = $feeder->surveyItems()->whereIn('status', [SurveyItemStatus::Submitted->value, SurveyItemStatus::Verified->value])->sum('transformers_surveyed');
                $remaining = max($feeder->total_transformers - $reported, 0);
                if ((int) $row['transformers_surveyed'] > $remaining) {
                    throw ValidationException::withMessages(['items' => "Feeder {$feeder->feeder_code} has only {$remaining} transformers remaining for survey."]);
                }

                $entry->items()->create([
                    'feeder_id' => $feeder->id,
                    'transformers_surveyed' => $row['transformers_surveyed'],
                    'drive_url' => $row['drive_url'] ?? $feeder->survey_drive_url,
                    'remarks' => $row['remarks'] ?? null,
                    'status' => SurveyItemStatus::Submitted,
                ]);
            }

            $this->audit->record($actor, 'survey.submitted', $entry, new: $entry->load('items')->toArray());

            return $entry;
        });
    }

    public function verify(User $actor, SurveyDailyEntryItem $item): void
    {
        Gate::forUser($actor)->authorize('verify', $item);

        DB::transaction(function () use ($actor, $item) {
            $item = SurveyDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== SurveyItemStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'This survey entry is no longer pending verification.']);
            }

            $old = $item->toArray();
            $item->update(['status' => SurveyItemStatus::Verified, 'verified_by' => $actor->id, 'verified_at' => now(), 'return_reason' => null]);
            $this->history($actor, $item, 'verified');
            $this->syncHeaderStatus($item->entry);
            $this->audit->record($actor, 'survey.verified', $item, $old, $item->fresh()->toArray());
        });
    }

    public function returnForCorrection(User $actor, SurveyDailyEntryItem $item, string $reason): void
    {
        Gate::forUser($actor)->authorize('return', $item);

        DB::transaction(function () use ($actor, $item, $reason) {
            $item = SurveyDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== SurveyItemStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'This survey entry is no longer pending verification.']);
            }

            $old = $item->toArray();
            $item->update(['status' => SurveyItemStatus::Returned, 'return_reason' => $reason, 'verified_by' => null, 'verified_at' => null]);
            $this->history($actor, $item, 'returned', $reason);
            $this->syncHeaderStatus($item->entry);
            $this->audit->record($actor, 'survey.returned', $item, $old, $item->fresh()->toArray(), $reason);
            $item->entry->enteredBy->notify(new SurveyReturnedNotification($item->fresh(['feeder'])));
        });
    }

    public function resubmit(User $actor, SurveyDailyEntryItem $item, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $item);

        DB::transaction(function () use ($actor, $item, $data) {
            $item = SurveyDailyEntryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->status !== SurveyItemStatus::Returned || $item->entry->entered_by !== $actor->id) {
                throw ValidationException::withMessages(['status' => 'Only your returned survey entry can be corrected.']);
            }

            $feeder = Feeder::query()->lockForUpdate()->findOrFail($item->feeder_id);
            $reported = $feeder->surveyItems()->whereKeyNot($item->id)->whereIn('status', [SurveyItemStatus::Submitted->value, SurveyItemStatus::Verified->value])->sum('transformers_surveyed');
            $remaining = max($feeder->total_transformers - $reported, 0);
            if ((int) $data['transformers_surveyed'] > $remaining) {
                throw ValidationException::withMessages(['transformers_surveyed' => "Feeder {$feeder->feeder_code} has only {$remaining} transformers remaining for survey."]);
            }

            $old = $item->toArray();
            $item->update([
                'transformers_surveyed' => $data['transformers_surveyed'],
                'drive_url' => $data['drive_url'] ?? $item->drive_url,
                'remarks' => $data['remarks'] ?? null,
                'status' => SurveyItemStatus::Submitted,
                'return_reason' => null,
                'resubmitted_at' => now(),
            ]);
            $this->history($actor, $item, 'resubmitted');
            $this->syncHeaderStatus($item->entry);
            $this->audit->record($actor, 'survey.resubmitted', $item, $old, $item->fresh()->toArray());
        });
    }

    private function history(User $actor, SurveyDailyEntryItem $item, string $action, ?string $comment = null): void
    {
        SurveyVerificationHistory::create([
            'survey_daily_entry_item_id' => $item->id,
            'action' => $action,
            'comment' => $comment,
            'acted_by' => $actor->id,
            'quantity_snapshot' => $item->transformers_surveyed,
            'acted_at' => now(),
        ]);
    }

    private function syncHeaderStatus(SurveyDailyEntry $entry): void
    {
        $statuses = $entry->items()->pluck('status');
        $status = match (true) {
            $statuses->every(fn ($value) => $value === SurveyItemStatus::Verified->value) => SurveyEntryStatus::Verified,
            $statuses->every(fn ($value) => $value === SurveyItemStatus::Returned->value) => SurveyEntryStatus::Returned,
            $statuses->contains(SurveyItemStatus::Verified->value) || $statuses->contains(SurveyItemStatus::Returned->value) => SurveyEntryStatus::PartiallyVerified,
            default => SurveyEntryStatus::Submitted,
        };
        $entry->update(['status' => $status]);
    }
}
