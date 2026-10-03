<?php

namespace App\Services;

use App\Enums\SurveyEntryStatus;
use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\MdbDailyEntry;
use App\Models\MdbTeam;
use App\Models\Project;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ManagementDashboardDemoService
{
    public const MARKER = '[SAMPLE DASHBOARD]';

    public function load(): array
    {
        return DB::transaction(function (): array {
            if (SurveyDailyEntry::where('remarks', 'like', self::MARKER.'%')->exists()) {
                return $this->summary('Sample dashboard data was already loaded.');
            }

            $project = Project::where('code', 'HAZECO-TDL')->firstOrFail();
            $admin = User::where('role', 'super_admin')->firstOrFail();
            $surveyUser = User::where('role', 'survey_team_leader')->firstOrFail();
            $mdbUser = User::where('role', 'mdb_team_user')->firstOrFail();
            $surveyTeam = SurveyTeam::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'ST-01'],
                ['name' => 'Survey Team 01', 'status' => 'active'],
            );
            $mdbTeam = MdbTeam::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'MDB-01'],
                ['name' => 'MDB Team 01', 'status' => 'active'],
            );
            $surveyTeam->members()->syncWithoutDetaching([$surveyUser->id => ['is_leader' => true]]);
            $mdbTeam->members()->syncWithoutDetaching([$mdbUser->id]);

            $circleIds = Feeder::where('project_id', $project->id)->distinct()->pluck('circle_id');
            $feeders = $circleIds->flatMap(fn (int $circleId) => Feeder::where('project_id', $project->id)
                ->where('circle_id', $circleId)
                ->orderBy('source_serial')
                ->limit(9)
                ->get())
                ->take(18)
                ->values();

            if ($feeders->count() < 6) {
                throw new RuntimeException('At least six imported HAZECO feeders are required for dashboard sample data.');
            }

            $surveyRatios = [1.00, 0.96, 0.90, 0.84, 0.78, 0.71, 0.63, 0.55, 0.46];
            $mdbRatios = [1.00, 0.88, 0.75, 0.62, 0.50, 0.38, 0.28, 0.18, 0.08];

            foreach ($feeders as $index => $feeder) {
                $baseline = 90 + (($index * 37) % 110);
                $feeder->update([
                    'total_transformers' => $baseline,
                    'baseline_pending' => false,
                    'demo_baseline' => true,
                ]);

                FeederAssignment::firstOrCreate(
                    ['feeder_id' => $feeder->id, 'survey_team_id' => $surveyTeam->id],
                    [
                        'assigned_by' => $admin->id,
                        'start_date' => today()->subDays(30),
                        'status' => 'active',
                        'remarks' => self::MARKER.' Sample feeder assignment.',
                    ],
                );

                $entryDate = today()->subDays(13 - ($index % 14));
                $surveyQuantity = max(1, (int) floor($baseline * $surveyRatios[$index % count($surveyRatios)]));
                $awaitingVerification = $index % 6 === 5;
                $verifiedAt = $entryDate->copy()->addDay()->endOfDay()->min(now());
                $surveyEntry = SurveyDailyEntry::create([
                    'entry_date' => $entryDate,
                    'survey_team_id' => $surveyTeam->id,
                    'entered_by' => $surveyUser->id,
                    'status' => $awaitingVerification ? SurveyEntryStatus::Submitted : SurveyEntryStatus::Verified,
                    'remarks' => self::MARKER.' Sample survey production.',
                    'submitted_at' => $entryDate->copy()->setTime(16, 0),
                ]);
                $item = $surveyEntry->items()->create([
                    'feeder_id' => $feeder->id,
                    'transformers_surveyed' => $surveyQuantity,
                    'remarks' => self::MARKER.' Sample survey quantity.',
                    'status' => $awaitingVerification ? SurveyItemStatus::Submitted : SurveyItemStatus::Verified,
                    'verified_by' => $awaitingVerification ? null : $mdbUser->id,
                    'verified_at' => $awaitingVerification ? null : $verifiedAt,
                ]);

                if (! $awaitingVerification) {
                    $item->history()->create([
                        'action' => 'verified',
                        'comment' => self::MARKER.' Sample verification.',
                        'acted_by' => $mdbUser->id,
                        'quantity_snapshot' => $surveyQuantity,
                        'acted_at' => $verifiedAt,
                    ]);

                    $mdbQuantity = max(1, (int) floor($surveyQuantity * $mdbRatios[$index % count($mdbRatios)]));
                    $mdbEntry = MdbDailyEntry::create([
                        'entry_date' => $entryDate->copy()->addDays(2)->min(today()),
                        'mdb_team_id' => $mdbTeam->id,
                        'entered_by' => $mdbUser->id,
                        'remarks' => self::MARKER.' Sample MDB creation production.',
                    ]);
                    $mdbEntry->items()->create([
                        'feeder_id' => $feeder->id,
                        'mdb_files_created' => $mdbQuantity,
                        'remarks' => self::MARKER.' Sample MDB quantity.',
                    ]);
                }
            }

            return $this->summary('Sample dashboard data loaded successfully.');
        });
    }

    public function remove(): array
    {
        return DB::transaction(function (): array {
            MdbDailyEntry::where('remarks', 'like', self::MARKER.'%')->get()->each->delete();
            SurveyDailyEntry::where('remarks', 'like', self::MARKER.'%')->get()->each->delete();
            FeederAssignment::where('remarks', 'like', self::MARKER.'%')->delete();
            Feeder::where('demo_baseline', true)->update([
                'total_transformers' => 0,
                'baseline_pending' => true,
                'demo_baseline' => false,
            ]);

            return $this->summary('Sample dashboard data removed.');
        });
    }

    private function summary(string $message): array
    {
        return [
            'message' => $message,
            'demo_feeders' => Feeder::where('demo_baseline', true)->count(),
            'survey_entries' => SurveyDailyEntry::where('remarks', 'like', self::MARKER.'%')->count(),
            'mdb_entries' => MdbDailyEntry::where('remarks', 'like', self::MARKER.'%')->count(),
        ];
    }
}
