<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\FeederAssignment;
use App\Models\MdbProcessingAssignment;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        if ($request->user()->hasRole(UserRole::MdbProcessingUser->value)) {
            $organization = $request->user()->organization;
            abort_unless($organization, 403, 'A processing user must belong to an organization.');
            $assignments = MdbProcessingAssignment::where('organization_id', $organization->id)->with(['feeder', 'progressItems'])->latest('assignment_date')->get();

            return view('dashboard.processing', ['summary' => $dashboard->organizationSummary($organization), 'assignments' => $assignments]);
        }

        if ($request->user()->hasRole(UserRole::SurveyTeamLeader->value)) {
            $teamIds = $request->user()->surveyTeams()->pluck('survey_teams.id');
            $feederIds = FeederAssignment::whereIn('survey_team_id', $teamIds)
                ->where('status', 'active')
                ->pluck('feeder_id')
                ->unique()
                ->values()
                ->all();
            $feeders = $dashboard->surveyFeederProgress($feederIds);
            $summary = $dashboard->surveySummaryFor($feeders);

            $circleProgress = $feeders->groupBy('circle_id')->map(function ($rows) {
                $eligible = $rows->filter(fn ($feeder) => ! $feeder->baseline_pending && $feeder->total_transformers > 0);
                $baseline = (int) $eligible->sum('total_transformers');
                $reported = (int) $eligible->sum('survey_reported');

                return (object) [
                    'name' => $rows->first()->circle?->name ?? 'Unassigned circle',
                    'baseline' => $baseline,
                    'survey_reported' => $reported,
                    'survey_verified' => (int) $eligible->sum('survey_verified'),
                    'survey_pending' => (int) $eligible->sum('survey_pending'),
                    'verification_pending' => (int) $eligible->sum('verification_pending'),
                    'percentage' => $baseline ? round($reported / $baseline * 100, 1) : 0,
                ];
            })->sortByDesc('survey_pending')->values();

            $priorityFeeders = $feeders
                ->filter(fn ($feeder) => ! $feeder->baseline_pending && ($feeder->survey_pending > 0 || $feeder->verification_pending > 0))
                ->sortByDesc(fn ($feeder) => $feeder->survey_pending + $feeder->verification_pending)
                ->take(8)
                ->values();
            $today = now()->startOfDay();

            return view('dashboard.survey', [
                'summary' => $summary,
                'today' => $dashboard->surveyPeriodTotals($today, $today, $feederIds),
                'week' => $dashboard->surveyPeriodTotals(now()->startOfWeek(), now(), $feederIds),
                'month' => $dashboard->surveyPeriodTotals(now()->startOfMonth(), now(), $feederIds),
                'feeders' => $feeders,
                'circleProgress' => $circleProgress,
                'priorityFeeders' => $priorityFeeders,
            ]);
        }

        $feederIds = null;
        $feeders = $dashboard->feederProgress();
        $today = now()->startOfDay();
        $showPerformance = $request->user()->hasAnyRole([UserRole::SuperAdmin->value, UserRole::ProjectManager->value, UserRole::ManagementViewer->value]);
        $summary = $dashboard->summaryFor($feeders);
        $trend = $dashboard->trend(max(7, min((int) $request->integer('days', 14), 30)), $feederIds);
        $decision = $dashboard->resourceDecision($summary, $trend->take(-7));
        $circleProgress = $dashboard->circleProgress($feeders);
        $priorityFeeders = $dashboard->priorityFeeders($feeders);
        $chartData = [
            'overall' => [
                ['label' => 'Survey reported', 'value' => $summary['survey_reported'], 'percent' => $summary['percentages']['survey_reported'], 'color' => '#2563eb'],
                ['label' => 'Survey verified', 'value' => $summary['survey_verified'], 'percent' => $summary['percentages']['survey_verified'], 'color' => '#0891b2'],
                ['label' => 'MDB created', 'value' => $summary['mdb_created'], 'percent' => $summary['percentages']['mdb_created'], 'color' => '#16a34a'],
            ],
            'survey' => [
                ['label' => 'Verified', 'value' => $summary['survey_verified'], 'color' => '#16a34a'],
                ['label' => 'Awaiting verification', 'value' => $summary['verification_pending'], 'color' => '#f59e0b'],
                ['label' => 'Not surveyed', 'value' => $summary['survey_pending'], 'color' => '#dbe3ec'],
            ],
            'mdb' => [
                ['label' => 'MDB created', 'value' => $summary['mdb_created'], 'color' => '#16a34a'],
                ['label' => 'Ready but pending', 'value' => $summary['mdb_creation_backlog'], 'color' => '#ef4444'],
            ],
            'clearance' => [
                ['label' => 'Survey backlog', 'value' => $decision['survey_clear_days'] ?? 0, 'color' => '#2563eb'],
                ['label' => 'MDB backlog', 'value' => $decision['mdb_clear_days'] ?? 0, 'color' => '#ef4444'],
            ],
            'circles' => $circleProgress->map(fn ($row) => [
                'label' => $row->name,
                'survey' => $row->survey_pending,
                'mdb' => $row->mdb_backlog,
            ])->values(),
            'feeders' => $priorityFeeders->map(fn ($feeder) => [
                'label' => $feeder->feeder_code,
                'survey' => $feeder->survey_pending,
                'mdb' => $feeder->mdb_creation_backlog,
            ])->values(),
        ];

        return view('dashboard.index', [
            'summary' => $summary,
            'today' => $dashboard->periodTotals($today, $today, $feederIds),
            'week' => $dashboard->periodTotals(now()->startOfWeek(), now(), $feederIds),
            'month' => $dashboard->periodTotals(now()->startOfMonth(), now(), $feederIds),
            'feeders' => $feeders,
            'trend' => $trend,
            'decision' => $decision,
            'circleProgress' => $circleProgress,
            'priorityFeeders' => $priorityFeeders,
            'chartData' => $chartData,
            'surveyPerformance' => $showPerformance ? $dashboard->surveyTeamPerformance() : collect(),
            'mdbPerformance' => $showPerformance ? $dashboard->mdbUserPerformance() : collect(),
            'processingPerformance' => $showPerformance ? $dashboard->processingOrganizationPerformance() : collect(),
        ]);
    }
}
