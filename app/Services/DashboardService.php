<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\MdbProcessingAssignment;
use App\Models\MdbProcessingDailyEntryItem;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function surveyFeederProgress(?array $feederIds = null): Collection
    {
        $survey = DB::table('survey_daily_entry_items')
            ->select('feeder_id')
            ->selectRaw("SUM(CASE WHEN status IN ('submitted','verified') THEN transformers_surveyed ELSE 0 END) AS survey_reported")
            ->selectRaw("SUM(CASE WHEN status = 'verified' THEN transformers_surveyed ELSE 0 END) AS survey_verified")
            ->selectRaw("SUM(CASE WHEN status = 'submitted' THEN transformers_surveyed ELSE 0 END) AS verification_pending")
            ->groupBy('feeder_id');

        return Feeder::query()
            ->with(['gridStation:id,name', 'circle:id,name'])
            ->when($feederIds !== null, fn (Builder $query) => $query->whereIn('feeders.id', $feederIds))
            ->leftJoinSub($survey, 'survey_totals', 'survey_totals.feeder_id', '=', 'feeders.id')
            ->select('feeders.*')
            ->selectRaw('COALESCE(survey_reported, 0) AS survey_reported')
            ->selectRaw('COALESCE(survey_verified, 0) AS survey_verified')
            ->selectRaw('COALESCE(verification_pending, 0) AS verification_pending')
            ->orderBy('feeders.feeder_code')
            ->get()
            ->map(function (Feeder $feeder) {
                $feeder->survey_pending = max($feeder->total_transformers - $feeder->survey_reported, 0);
                $feeder->survey_status = match (true) {
                    $feeder->baseline_pending || $feeder->total_transformers === 0 => 'BASELINE PENDING',
                    $feeder->survey_verified >= $feeder->total_transformers => 'SURVEY COMPLETE',
                    $feeder->verification_pending > 0 => 'VERIFICATION PENDING',
                    $feeder->survey_reported > 0 => 'SURVEY IN PROGRESS',
                    default => 'NOT STARTED',
                };

                return $feeder;
            });
    }

    public function feederProgress(?int $projectId = null): Collection
    {
        $survey = DB::table('survey_daily_entry_items')
            ->select('feeder_id')
            ->selectRaw("SUM(CASE WHEN status IN ('submitted','verified') THEN transformers_surveyed ELSE 0 END) AS survey_reported")
            ->selectRaw("SUM(CASE WHEN status = 'verified' THEN transformers_surveyed ELSE 0 END) AS survey_verified")
            ->selectRaw("SUM(CASE WHEN status = 'submitted' THEN transformers_surveyed ELSE 0 END) AS verification_pending")
            ->groupBy('feeder_id');
        $mdb = DB::table('mdb_daily_entry_items')->select('feeder_id')->selectRaw('SUM(mdb_files_created) AS mdb_created')->groupBy('feeder_id');
        $assigned = DB::table('mdb_processing_assignments')
            ->select('feeder_id')->selectRaw('SUM(assigned_quantity) AS mdb_assigned')
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])->groupBy('feeder_id');
        $processed = DB::table('mdb_processing_daily_entry_items as pi')
            ->join('mdb_processing_assignments as pa', 'pa.id', '=', 'pi.mdb_processing_assignment_id')
            ->select('pa.feeder_id')->selectRaw('SUM(pi.mdb_processed) AS mdb_processed')->groupBy('pa.feeder_id');

        return Feeder::query()
            ->with(['gridStation:id,name', 'circle:id,name', 'division:id,name'])
            ->when($projectId, fn (Builder $query) => $query->where('project_id', $projectId))
            ->leftJoinSub($survey, 'survey_totals', 'survey_totals.feeder_id', '=', 'feeders.id')
            ->leftJoinSub($mdb, 'mdb_totals', 'mdb_totals.feeder_id', '=', 'feeders.id')
            ->leftJoinSub($assigned, 'assignment_totals', 'assignment_totals.feeder_id', '=', 'feeders.id')
            ->leftJoinSub($processed, 'processing_totals', 'processing_totals.feeder_id', '=', 'feeders.id')
            ->select('feeders.*')
            ->selectRaw('COALESCE(survey_reported, 0) AS survey_reported')
            ->selectRaw('COALESCE(survey_verified, 0) AS survey_verified')
            ->selectRaw('COALESCE(verification_pending, 0) AS verification_pending')
            ->selectRaw('COALESCE(mdb_created, 0) AS mdb_created')
            ->selectRaw('COALESCE(mdb_assigned, 0) AS mdb_assigned')
            ->selectRaw('COALESCE(mdb_processed, 0) AS mdb_processed')
            ->orderBy('feeders.feeder_code')
            ->get()
            ->map(function (Feeder $feeder) {
                $feeder->survey_pending = max($feeder->total_transformers - $feeder->survey_reported, 0);
                $feeder->mdb_creation_backlog = max($feeder->survey_verified - $feeder->mdb_created, 0);
                $feeder->mdb_processing_backlog = max($feeder->mdb_created - $feeder->mdb_processed, 0);
                $feeder->unassigned_mdb = max($feeder->mdb_created - $feeder->mdb_assigned, 0);
                $feeder->progress_status = $this->statusFor($feeder);

                return $feeder;
            });
    }

    public function summary(?int $projectId = null): array
    {
        return $this->summaryFor($this->feederProgress($projectId));
    }

    public function surveySummaryFor(Collection $feeders): array
    {
        $eligible = $feeders->filter(fn (Feeder $feeder) => ! $feeder->baseline_pending && $feeder->total_transformers > 0);
        $baseline = (int) $eligible->sum('total_transformers');
        $reported = (int) $eligible->sum('survey_reported');
        $verified = (int) $eligible->sum('survey_verified');

        return [
            'total_transformers' => $baseline,
            'survey_reported' => $reported,
            'survey_verified' => $verified,
            'survey_pending' => (int) $eligible->sum('survey_pending'),
            'verification_pending' => (int) $eligible->sum('verification_pending'),
            'total_feeders' => $feeders->count(),
            'baselined_feeders' => $eligible->count(),
            'baseline_pending_feeders' => $feeders->where('baseline_pending', true)->count(),
            'percentages' => [
                'survey_reported' => $baseline ? round($reported / $baseline * 100, 1) : 0,
                'survey_verified' => $baseline ? round($verified / $baseline * 100, 1) : 0,
            ],
        ];
    }

    public function summaryFor(Collection $feeders): array
    {
        $eligible = $feeders->filter(fn (Feeder $feeder) => ! $feeder->baseline_pending && $feeder->total_transformers > 0);
        $keys = ['total_transformers', 'survey_reported', 'survey_verified', 'survey_pending', 'verification_pending', 'mdb_created', 'mdb_creation_backlog', 'mdb_assigned', 'mdb_processed', 'mdb_processing_backlog', 'unassigned_mdb'];
        $summary = collect($keys)->mapWithKeys(fn ($key) => [$key => (int) $eligible->sum($key)])->all();
        $summary += [
            'total_feeders' => $feeders->count(),
            'baselined_feeders' => $eligible->count(),
            'baseline_pending_feeders' => $feeders->where('baseline_pending', true)->count(),
            'demo_feeders' => $feeders->where('demo_baseline', true)->count(),
        ];
        $baseline = max($summary['total_transformers'], 1);
        $verified = max($summary['survey_verified'], 1);
        $summary['percentages'] = [
            'survey_reported' => round($summary['survey_reported'] / $baseline * 100, 1),
            'survey_verified' => round($summary['survey_verified'] / $baseline * 100, 1),
            'mdb_created' => round($summary['mdb_created'] / $baseline * 100, 1),
            'mdb_eligible' => round($summary['mdb_created'] / $verified * 100, 1),
            'mdb_processed' => round($summary['mdb_processed'] / $baseline * 100, 1),
        ];

        return $summary;
    }

    public function resourceDecision(array $summary, Collection $trend): array
    {
        $days = max($trend->count(), 1);
        $surveyRate = round($trend->sum('survey') / $days, 1);
        $mdbRate = round($trend->sum('mdb') / $days, 1);
        $surveyDays = $summary['survey_pending'] > 0 && $surveyRate > 0
            ? (int) ceil($summary['survey_pending'] / $surveyRate)
            : null;
        $mdbDays = $summary['mdb_creation_backlog'] > 0 && $mdbRate > 0
            ? (int) ceil($summary['mdb_creation_backlog'] / $mdbRate)
            : null;

        if ($summary['total_transformers'] === 0) {
            return [
                'focus' => 'Verify transformer baselines',
                'tone' => 'warning',
                'reason' => 'Progress cannot be measured until feeder transformer baselines are verified.',
                'survey_daily_rate' => $surveyRate,
                'mdb_daily_rate' => $mdbRate,
                'survey_clear_days' => $surveyDays,
                'mdb_clear_days' => $mdbDays,
            ];
        }

        $surveyScore = $surveyDays ?? ($summary['survey_pending'] > 0 ? PHP_INT_MAX : 0);
        $mdbScore = $mdbDays ?? ($summary['mdb_creation_backlog'] > 0 ? PHP_INT_MAX : 0);

        if ($summary['survey_pending'] === 0 && $summary['mdb_creation_backlog'] === 0) {
            $focus = 'Maintain current staffing';
            $tone = 'success';
            $reason = 'There is no current survey or MDB creation backlog for baselined feeders.';
        } elseif ($mdbScore > $surveyScore) {
            $focus = 'Add MDB creation resources';
            $tone = 'danger';
            $reason = number_format($summary['mdb_creation_backlog']).' verified surveys are ready but still waiting for MDB creation.';
        } else {
            $focus = 'Add survey resources';
            $tone = 'warning';
            $reason = number_format($summary['survey_pending']).' transformers remain for field survey on baselined feeders.';
        }

        return [
            'focus' => $focus,
            'tone' => $tone,
            'reason' => $reason,
            'survey_daily_rate' => $surveyRate,
            'mdb_daily_rate' => $mdbRate,
            'survey_clear_days' => $surveyDays,
            'mdb_clear_days' => $mdbDays,
        ];
    }

    public function circleProgress(Collection $feeders): Collection
    {
        return $feeders->groupBy('circle_id')->map(function (Collection $rows) {
            $eligible = $rows->filter(fn (Feeder $feeder) => ! $feeder->baseline_pending && $feeder->total_transformers > 0);
            $baseline = (int) $eligible->sum('total_transformers');
            $surveyReported = (int) $eligible->sum('survey_reported');
            $surveyVerified = (int) $eligible->sum('survey_verified');
            $surveyPending = (int) $eligible->sum('survey_pending');
            $mdbCreated = (int) $eligible->sum('mdb_created');
            $mdbBacklog = (int) $eligible->sum('mdb_creation_backlog');

            return (object) [
                'name' => $rows->first()->circle?->name ?? 'Unassigned circle',
                'feeders' => $rows->count(),
                'baselined_feeders' => $eligible->count(),
                'baseline' => $baseline,
                'survey_reported' => $surveyReported,
                'survey_verified' => $surveyVerified,
                'survey_pending' => $surveyPending,
                'mdb_created' => $mdbCreated,
                'mdb_backlog' => $mdbBacklog,
                'survey_percentage' => $baseline ? round($surveyReported / $baseline * 100, 1) : 0,
                'mdb_percentage' => $surveyVerified ? round($mdbCreated / $surveyVerified * 100, 1) : 0,
                'focus' => $mdbBacklog > $surveyPending ? 'MDB creation' : ($surveyPending > 0 ? 'Survey' : 'On track'),
            ];
        })->sortByDesc(fn ($row) => $row->survey_pending + $row->mdb_backlog)->values();
    }

    public function priorityFeeders(Collection $feeders, int $limit = 8): Collection
    {
        return $feeders
            ->filter(fn (Feeder $feeder) => ! $feeder->baseline_pending && ($feeder->survey_pending > 0 || $feeder->mdb_creation_backlog > 0))
            ->sortByDesc(fn (Feeder $feeder) => $feeder->survey_pending + $feeder->mdb_creation_backlog)
            ->take($limit)
            ->values();
    }

    public function periodTotals(Carbon $from, Carbon $to, ?array $feederIds = null): array
    {
        $surveyTotals = $this->surveyPeriodTotals($from, $to, $feederIds);
        $mdb = DB::table('mdb_daily_entry_items as i')->join('mdb_daily_entries as e', 'e.id', '=', 'i.mdb_daily_entry_id')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()]);
        $processed = DB::table('mdb_processing_daily_entry_items as i')->join('mdb_processing_daily_entries as e', 'e.id', '=', 'i.mdb_processing_daily_entry_id')->join('mdb_processing_assignments as a', 'a.id', '=', 'i.mdb_processing_assignment_id')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()]);
        if ($feederIds !== null) {
            $mdb->whereIn('i.feeder_id', $feederIds);
            $processed->whereIn('a.feeder_id', $feederIds);
        }

        return $surveyTotals + [
            'mdb_created' => (int) $mdb->sum('i.mdb_files_created'),
            'mdb_processed' => (int) $processed->sum('i.mdb_processed'),
        ];
    }

    public function surveyPeriodTotals(Carbon $from, Carbon $to, ?array $feederIds = null): array
    {
        $survey = DB::table('survey_daily_entry_items as i')
            ->join('survey_daily_entries as e', 'e.id', '=', 'i.survey_daily_entry_id')
            ->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('i.status', [SurveyItemStatus::Submitted->value, SurveyItemStatus::Verified->value]);
        $verified = DB::table('survey_daily_entry_items')
            ->where('status', SurveyItemStatus::Verified->value)
            ->whereBetween('verified_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($feederIds !== null) {
            $survey->whereIn('i.feeder_id', $feederIds);
            $verified->whereIn('feeder_id', $feederIds);
        }

        return [
            'survey_reported' => (int) $survey->sum('i.transformers_surveyed'),
            'survey_verified' => (int) $verified->sum('transformers_surveyed'),
        ];
    }

    public function trend(int $days = 30, ?array $feederIds = null): Collection
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $survey = DB::table('survey_daily_entry_items as i')->join('survey_daily_entries as e', 'e.id', '=', 'i.survey_daily_entry_id')
            ->whereDate('e.entry_date', '>=', $start)->whereIn('i.status', [SurveyItemStatus::Submitted->value, SurveyItemStatus::Verified->value]);
        $mdb = DB::table('mdb_daily_entry_items as i')->join('mdb_daily_entries as e', 'e.id', '=', 'i.mdb_daily_entry_id')
            ->whereDate('e.entry_date', '>=', $start);
        $processing = DB::table('mdb_processing_daily_entry_items as i')->join('mdb_processing_daily_entries as e', 'e.id', '=', 'i.mdb_processing_daily_entry_id')
            ->join('mdb_processing_assignments as a', 'a.id', '=', 'i.mdb_processing_assignment_id')->whereDate('e.entry_date', '>=', $start);
        if ($feederIds !== null) {
            $survey->whereIn('i.feeder_id', $feederIds);
            $mdb->whereIn('i.feeder_id', $feederIds);
            $processing->whereIn('a.feeder_id', $feederIds);
        }
        $survey = $survey->select('e.entry_date')->selectRaw('SUM(i.transformers_surveyed) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');
        $mdb = $mdb->select('e.entry_date')->selectRaw('SUM(i.mdb_files_created) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');
        $processing = $processing->select('e.entry_date')->selectRaw('SUM(i.mdb_processed) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');

        return collect(range(0, $days - 1))->map(function ($offset) use ($start, $survey, $mdb, $processing) {
            $date = $start->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'survey' => (int) ($survey[$date] ?? 0), 'mdb' => (int) ($mdb[$date] ?? 0), 'processed' => (int) ($processing[$date] ?? 0)];
        });
    }

    public function organizationSummary(Organization $organization): array
    {
        $assignments = MdbProcessingAssignment::query()->where('organization_id', $organization->id)->withSum('progressItems', 'mdb_processed')->get();
        $assigned = (int) $assignments->sum('assigned_quantity');
        $processed = (int) $assignments->sum('progress_items_sum_mdb_processed');
        $today = (int) MdbProcessingDailyEntryItem::query()->whereHas('entry', fn (Builder $query) => $query->where('organization_id', $organization->id)->whereDate('entry_date', today()))->sum('mdb_processed');

        return compact('assigned', 'processed', 'today') + ['remaining' => max($assigned - $processed, 0), 'completion' => $assigned ? round($processed / $assigned * 100, 1) : 0];
    }

    public function surveyTeamPerformance(): Collection
    {
        $today = today()->toDateString();
        $week = today()->startOfWeek()->toDateString();
        $month = today()->startOfMonth()->toDateString();

        return DB::table('survey_teams as t')
            ->leftJoin('survey_daily_entries as e', 'e.survey_team_id', '=', 't.id')
            ->leftJoin('survey_daily_entry_items as i', 'i.survey_daily_entry_id', '=', 'e.id')
            ->select('t.id', 't.name')
            ->selectRaw("SUM(CASE WHEN i.status IN ('submitted','verified') AND e.entry_date = ? THEN i.transformers_surveyed ELSE 0 END) reported_today", [$today])
            ->selectRaw("SUM(CASE WHEN i.status = 'verified' AND DATE(i.verified_at) = ? THEN i.transformers_surveyed ELSE 0 END) verified_today", [$today])
            ->selectRaw("SUM(CASE WHEN i.status = 'returned' THEN 1 ELSE 0 END) returned")
            ->selectRaw("SUM(CASE WHEN i.status IN ('submitted','verified') AND e.entry_date >= ? THEN i.transformers_surveyed ELSE 0 END) this_week", [$week])
            ->selectRaw("SUM(CASE WHEN i.status IN ('submitted','verified') AND e.entry_date >= ? THEN i.transformers_surveyed ELSE 0 END) this_month", [$month])
            ->selectRaw("SUM(CASE WHEN i.status IN ('submitted','verified') THEN i.transformers_surveyed ELSE 0 END) overall")
            ->groupBy('t.id', 't.name')->orderBy('t.name')->get();
    }

    public function mdbUserPerformance(): Collection
    {
        $today = today()->toDateString();
        $week = today()->startOfWeek()->toDateString();
        $month = today()->startOfMonth()->toDateString();

        return DB::table('users as u')
            ->leftJoin('mdb_daily_entries as e', 'e.entered_by', '=', 'u.id')
            ->leftJoin('mdb_daily_entry_items as i', 'i.mdb_daily_entry_id', '=', 'e.id')
            ->where('u.role', 'mdb_team_user')
            ->select('u.id', 'u.name')
            ->selectRaw('SUM(CASE WHEN e.entry_date = ? THEN i.mdb_files_created ELSE 0 END) today', [$today])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN i.mdb_files_created ELSE 0 END) this_week', [$week])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN i.mdb_files_created ELSE 0 END) this_month', [$month])
            ->selectRaw('COALESCE(SUM(i.mdb_files_created), 0) overall')
            ->groupBy('u.id', 'u.name')->orderBy('u.name')->get();
    }

    public function processingOrganizationPerformance(): Collection
    {
        $today = today()->toDateString();
        $week = today()->startOfWeek()->toDateString();
        $month = today()->startOfMonth()->toDateString();
        $assigned = DB::table('mdb_processing_assignments')->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
            ->select('organization_id')->selectRaw('SUM(assigned_quantity) assigned')->groupBy('organization_id');
        $processed = DB::table('mdb_processing_daily_entries as e')->join('mdb_processing_daily_entry_items as i', 'i.mdb_processing_daily_entry_id', '=', 'e.id')
            ->select('e.organization_id')->selectRaw('SUM(i.mdb_processed) processed')
            ->selectRaw('SUM(CASE WHEN e.entry_date = ? THEN i.mdb_processed ELSE 0 END) today', [$today])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN i.mdb_processed ELSE 0 END) this_week', [$week])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN i.mdb_processed ELSE 0 END) this_month', [$month])
            ->groupBy('e.organization_id');

        return DB::table('organizations as o')->leftJoinSub($assigned, 'a', 'a.organization_id', '=', 'o.id')->leftJoinSub($processed, 'p', 'p.organization_id', '=', 'o.id')
            ->whereNull('o.deleted_at')->select('o.id', 'o.name', 'o.type')
            ->selectRaw('COALESCE(a.assigned, 0) assigned')->selectRaw('COALESCE(p.processed, 0) processed')
            ->selectRaw('COALESCE(p.today, 0) today')->selectRaw('COALESCE(p.this_week, 0) this_week')->selectRaw('COALESCE(p.this_month, 0) this_month')
            ->orderBy('o.name')->get()->map(function ($row) {
                $row->remaining = max($row->assigned - $row->processed, 0);
                $row->completion = $row->assigned ? round($row->processed / $row->assigned * 100, 1) : 0;

                return $row;
            });
    }

    private function statusFor(Feeder $feeder): string
    {
        if ($feeder->baseline_pending || $feeder->total_transformers === 0) {
            return 'BASELINE PENDING';
        }

        $complete = $feeder->survey_verified >= $feeder->total_transformers
            && $feeder->mdb_created >= $feeder->total_transformers
            && (! $feeder->processing_required || $feeder->mdb_processed >= $feeder->total_transformers);

        return match (true) {
            $complete => 'COMPLETED',
            $feeder->mdb_processed > 0 || $feeder->mdb_assigned > 0 => 'MDB PROCESSING RUNNING',
            $feeder->mdb_created > 0 || $feeder->survey_verified > 0 => 'MDB CREATION RUNNING',
            $feeder->verification_pending > 0 => 'VERIFICATION PENDING',
            $feeder->survey_reported > 0 => 'SURVEY RUNNING',
            default => 'NOT STARTED',
        };
    }
}
