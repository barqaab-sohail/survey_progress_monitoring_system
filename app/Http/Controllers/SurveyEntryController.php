<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Http\Requests\ResubmitSurveyItemRequest;
use App\Http\Requests\StoreSurveyEntryRequest;
use App\Models\Feeder;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyDailyEntryItem;
use App\Models\SurveyTeam;
use App\Services\SurveyProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SurveyEntryController extends Controller
{
    public function index(Request $request): View
    {
        $entries = SurveyDailyEntry::query()
            ->unless($request->user()->hasRole(UserRole::SuperAdmin->value), fn ($query) => $query->where('entered_by', $request->user()->id))
            ->with(['team', 'items.feeder'])->latest('entry_date')->paginate(20);

        return view('survey.index', compact('entries'));
    }

    public function create(Request $request): View
    {
        $team = $this->teamFor($request);
        $feeders = Feeder::active()->whereHas('assignments', fn ($query) => $query->where('survey_team_id', $team->id)->where('status', 'active'))->orderBy('feeder_code')->get();

        return view('survey.create', compact('team', 'feeders'));
    }

    public function store(StoreSurveyEntryRequest $request, SurveyProgressService $service): RedirectResponse
    {
        $team = $this->teamFor($request);
        $service->create($request->user(), $team, $request->validated());

        return redirect()->route('survey.index')->with('success', 'Daily survey progress submitted for verification.');
    }

    public function returned(Request $request): View
    {
        $items = SurveyDailyEntryItem::where('status', SurveyItemStatus::Returned->value)->whereHas('entry', fn ($query) => $query->where('entered_by', $request->user()->id))->with(['entry', 'feeder'])->latest()->paginate(20);

        return view('survey.returned', compact('items'));
    }

    public function update(ResubmitSurveyItemRequest $request, SurveyDailyEntryItem $item, SurveyProgressService $service): RedirectResponse
    {
        $service->resubmit($request->user(), $item, $request->validated());

        return redirect()->route('survey.returned')->with('success', 'Survey item corrected and resubmitted.');
    }

    private function teamFor(Request $request): SurveyTeam
    {
        $team = $request->user()->surveyTeams()->first();
        if ($team) {
            return $team;
        }

        abort_unless($request->user()->hasRole(UserRole::SuperAdmin->value), 403, 'You are not assigned to a survey team.');

        return SurveyTeam::firstOrFail();
    }
}
