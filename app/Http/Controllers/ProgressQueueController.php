<?php

namespace App\Http\Controllers;

use App\Models\FeederAssignment;
use App\Models\MdbDailyEntryItem;
use App\Models\SurveyDailyEntryItem;
use App\Policies\MdbDailyEntryItemPolicy;
use App\Services\DashboardService;
use App\Support\ReviewAging;
use Illuminate\Http\Request;

class ProgressQueueController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard)
    {
        $filters = $request->validate([
            'stage' => ['required', 'in:baseline,survey,verification,mdb_creation,mdb_verification,mdb_returned'],
            'feeder_id' => ['nullable', 'integer', 'exists:feeders,id'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $stage = $filters['stage'];
        $user = $request->user();
        if ($user->hasRole('mdb_processing_user')) {
            abort_unless(app(MdbDailyEntryItemPolicy::class)->verifyAny($user), 403);
        }
        $surveyOnly = $user->hasRole('survey_team_leader');
        abort_if($surveyOnly && ! in_array($stage, ['baseline', 'survey', 'verification']), 403);
        abort_if($user->hasRole('mdb_processing_user') && ! in_array($stage, ['mdb_verification', 'mdb_returned']), 403);
        $ids = $surveyOnly ? FeederAssignment::whereIn('survey_team_id', $user->surveyTeams()->pluck('survey_teams.id'))->where('status', 'active')->pluck('feeder_id')->all() : null;
        $titles = ['baseline' => 'Baseline pending feeders', 'survey' => 'Survey pending feeders', 'verification' => 'Survey awaiting verification', 'mdb_creation' => 'Ready for MDB creation', 'mdb_verification' => 'MDB awaiting verification', 'mdb_returned' => 'MDB returned for correction'];
        $title = $titles[$stage];
        $items = null;
        $aging = null;
        $feeders = collect();
        if (in_array($stage, ['verification', 'mdb_verification', 'mdb_returned'])) {
            $query = $stage === 'verification' ? SurveyDailyEntryItem::query() : MdbDailyEntryItem::query();
            $query->where('status', $stage === 'mdb_returned' ? 'returned' : 'submitted')
                ->when($ids !== null, fn ($q) => $q->whereIn('feeder_id', $ids));
            if ($stage === 'mdb_returned') {
                $query->when($filters['feeder_id'] ?? null, fn ($q, $id) => $q->where('feeder_id', $id));
                $items = $query->with(['entry.enteredBy', 'feeder'])->oldest('updated_at')->paginate(30)->withQueryString();
            } else {
                $query = ReviewAging::query($query, $filters);
                $aging = ReviewAging::summary($query);
                $items = $query->with(['entry.enteredBy', 'entry.team', 'feeder'])->orderByRaw('COALESCE(resubmitted_at, created_at)')->paginate(30)->withQueryString();
            }
        } else {
            $feeders = ($surveyOnly ? $dashboard->surveyFeederProgress($ids) : $dashboard->feederProgress())
                ->filter(fn ($f) => empty($filters['feeder_id']) || $f->id === (int) $filters['feeder_id'])
                ->filter(fn ($f) => match ($stage) {
                    'baseline' => $f->baseline_pending || $f->total_transformers < 1,
                    'survey' => ! $f->baseline_pending && $f->total_transformers > 0 && $f->survey_pending > 0,
                    default => ! $f->baseline_pending && $f->total_transformers > 0 && $f->mdb_creation_backlog > 0,
                });
        }

        return view('dashboard.queue', compact('stage', 'title', 'items', 'aging', 'feeders'));
    }
}
