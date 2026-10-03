<?php

namespace App\Services;

use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\MdbDailyEntry;
use App\Models\MdbTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MdbCreationService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(User $actor, ?MdbTeam $team, array $data): MdbDailyEntry
    {
        Gate::forUser($actor)->authorize('create', MdbDailyEntry::class);

        return DB::transaction(function () use ($actor, $team, $data) {
            $entry = MdbDailyEntry::create([
                'entry_date' => $data['entry_date'],
                'mdb_team_id' => $team?->id,
                'entered_by' => $actor->id,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['items'] as $row) {
                $feeder = Feeder::query()->lockForUpdate()->findOrFail($row['feeder_id']);
                $verified = $feeder->surveyItems()->where('status', SurveyItemStatus::Verified->value)->sum('transformers_surveyed');
                $created = $feeder->mdbItems()->sum('mdb_files_created');
                $available = max($verified - $created, 0);
                if ((int) $row['mdb_files_created'] > $available) {
                    throw ValidationException::withMessages(['items' => "Only {$available} verified transformers are currently available for MDB creation for Feeder {$feeder->feeder_code}."]);
                }

                $entry->items()->create([
                    'feeder_id' => $feeder->id,
                    'mdb_files_created' => $row['mdb_files_created'],
                    'drive_url' => $row['drive_url'] ?? $feeder->mdb_drive_url,
                    'remarks' => $row['remarks'] ?? null,
                ]);
            }

            $this->audit->record($actor, 'mdb.created', $entry, new: $entry->load('items')->toArray());

            return $entry;
        });
    }
}
