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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SurveyEntryController extends Controller
{
    public function index(Request $request): View
    {
        $entries = SurveyDailyEntry::query()
            ->unless($request->user()->hasRole(UserRole::SuperAdmin->value), fn($query) => $query->where('entered_by', $request->user()->id))
            ->with(['team', 'items.feeder'])->latest('entry_date')->paginate(20);

        return view('survey.index', compact('entries'));
    }

    public function create(Request $request): View
    {
        $availableTeams = $this->availableTeamsFor($request);
        $team = $this->teamFor($request, $availableTeams);
        $feeders = Feeder::active()->whereHas('assignments', fn($query) => $query->where('survey_team_id', $team->id)->where('status', 'active'))->orderBy('feeder_code')->get();

        return view('survey.create', compact('team', 'feeders', 'availableTeams'));
    }

    public function store(StoreSurveyEntryRequest $request, SurveyProgressService $service): RedirectResponse
    {
        $team = $this->teamFor($request);
        $service->create($request->user(), $team, $request->validated());

        return redirect()->route('survey.index')->with('success', 'Daily survey progress submitted for verification.');
    }

    public function edit(SurveyDailyEntry $entry): View
    {
        $this->authorize('update', $entry);
        abort_unless($entry->canBeEdited(), 403, 'Survey editing is locked once verification begins.');
        $entry->load('items.feeder', 'team');
        $team = $entry->team;
        $feeders = $entry->items->pluck('feeder');

        return view('survey.create', compact('team', 'feeders', 'entry'));
    }

    public function updateEntry(StoreSurveyEntryRequest $request, SurveyDailyEntry $entry, SurveyProgressService $service): RedirectResponse
    {
        $this->authorize('update', $entry);
        $service->update($request->user(), $entry, $request->validated());

        return redirect()->route('survey.index')->with('success', 'Survey entry updated before verification.');
    }

    public function returned(Request $request): View
    {
        $items = SurveyDailyEntryItem::where('status', SurveyItemStatus::Returned->value)->whereHas('entry', fn($query) => $query->where('entered_by', $request->user()->id))->with(['entry', 'feeder'])->latest()->paginate(20);

        return view('survey.returned', compact('items'));
    }

    public function update(ResubmitSurveyItemRequest $request, SurveyDailyEntryItem $item, SurveyProgressService $service): RedirectResponse
    {
        $service->resubmit($request->user(), $item, $request->validated());

        return redirect()->route('survey.returned')->with('success', 'Survey item corrected and resubmitted.');
    }

    private function availableTeamsFor(Request $request): Collection
    {
        return SurveyTeam::query()->where('status', 'active')
            ->whereHas('project', fn($query) => $query->where('status', 'active'))
            ->unless($request->user()->hasRole(UserRole::SuperAdmin->value),
                fn($query) => $query->whereHas('members', fn($members) => $members->where('users.id', $request->user()->id)))
            ->with('project')->orderBy('id')->get();
    }

    private function teamFor(Request $request, ?Collection $availableTeams = null): SurveyTeam
    {
        $data = $request->validate(['survey_team_id' => ['nullable', 'integer']]);
        $availableTeams ??= $this->availableTeamsFor($request);
        abort_if($availableTeams->isEmpty(), 403, 'You are not assigned to a survey team.');

        $selectedId = $data['survey_team_id'] ?? null;
        if ($selectedId === null && $request->isMethod('GET')) {
            $oldId = $request->old('survey_team_id');
            if (is_scalar($oldId) && filter_var($oldId, FILTER_VALIDATE_INT) !== false) {
                $selectedId = $oldId;
            }
        }
        if ($selectedId !== null) {
            $team = $availableTeams->firstWhere('id', (int) $selectedId);
            abort_unless($team, 403, 'The selected survey team is not active or assigned to you.');

            return $team;
        }

        return $availableTeams->first();
    }
}
