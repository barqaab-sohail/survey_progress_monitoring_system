<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.teams.index', [
            'surveyTeams' => SurveyTeam::with(['members', 'project'])->orderBy('name')->get(), 'mdbTeams' => MdbTeam::with(['members', 'project'])->orderBy('name')->get(),
            'projects' => Project::where('status', 'active')->get(), 'organizations' => Organization::where('status', 'active')->get(), 'users' => User::where('status', 'active')->orderBy('name')->get(),
            'feeders' => Feeder::active()->orderBy('feeder_code')->get(), 'assignments' => FeederAssignment::with(['feeder', 'surveyTeam'])->where('status', 'active')->get(),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:survey,mdb'], 'project_id' => ['required', 'exists:projects,id'], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255']]);
        $attributes = ['project_id' => $data['project_id'], 'code' => $data['code'], 'name' => $data['name'], 'status' => 'active'];
        $team = match ($data['type']) {
            'survey' => SurveyTeam::create($attributes), 'mdb' => MdbTeam::create($attributes),
        };
        $audit->record($request->user(), 'team.created', $team, new: $team->toArray());

        return back()->with('success', 'Team created.');
    }

    public function member(Request $request): RedirectResponse
    {
        $teamTable = $request->input('type') === 'mdb' ? 'mdb_teams' : 'survey_teams';
        $data = $request->validate([
            'type' => ['required', 'in:survey,mdb'],
            'team_id' => ['required', 'integer', Rule::exists($teamTable, 'id')->where(fn ($query) => $query
                ->where('status', 'active')->whereIn('project_id', Project::where('status', 'active')->select('id')))],
            'user_id' => ['required', Rule::exists('users', 'id')->where('status', 'active')],
            'is_leader' => ['nullable', 'boolean'],
        ]);
        $team = match ($data['type']) {
            'survey' => SurveyTeam::findOrFail($data['team_id']), 'mdb' => MdbTeam::findOrFail($data['team_id'])
        };
        $team->members()->syncWithoutDetaching([$data['user_id'] => $data['type'] === 'survey' ? ['is_leader' => $request->boolean('is_leader')] : []]);

        return back()->with('success', 'Team member assigned.');
    }

    public function assignFeeder(Request $request, AuditService $audit): RedirectResponse
    {
        $scope = $request->input('assignment_scope', 'single');
        $data = $request->validate([
            'assignment_scope' => ['sometimes', 'required', 'in:single,all'],
            'feeder_id' => [Rule::excludeIf($scope === 'all'), 'required', 'integer', Rule::exists('feeders', 'id')->where('status', 'active')],
            'survey_team_id' => ['required', 'integer', Rule::exists('survey_teams', 'id')->where(fn ($query) => $query
                ->where('status', 'active')->whereIn('project_id', Project::where('status', 'active')->select('id')))],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        [$created, $skipped, $teamName] = DB::transaction(function () use ($request, $data, $scope, $audit) {
            // Serialize both single and bulk requests for the same team to avoid duplicates.
            $team = SurveyTeam::whereKey($data['survey_team_id'])->where('status', 'active')->lockForUpdate()->first();
            if (! $team || ! $team->project()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['survey_team_id' => 'Select an active survey team in an active project.']);
            }

            $feeders = Feeder::active()->where('project_id', $team->project_id)
                ->when($scope === 'single', fn ($query) => $query->whereKey($data['feeder_id']))
                ->orderBy('id')->lockForUpdate()->get();
            if ($feeders->isEmpty()) {
                throw ValidationException::withMessages($scope === 'all'
                    ? ['assignment_scope' => 'There are no active feeders in this survey team\'s project.']
                    : ['feeder_id' => 'Select an active feeder from the survey team\'s project.']);
            }

            $assignedIds = FeederAssignment::where('survey_team_id', $team->id)->where('status', 'active')
                ->whereIn('feeder_id', $feeders->modelKeys())->lockForUpdate()->pluck('feeder_id')->all();
            $newFeeders = $feeders->reject(fn ($feeder) => in_array($feeder->id, $assignedIds));
            foreach ($newFeeders as $feeder) {
                FeederAssignment::create([
                    'feeder_id' => $feeder->id,
                    'survey_team_id' => $team->id,
                    'assigned_by' => $request->user()->id,
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'status' => 'active',
                ]);
            }
            if ($newFeeders->isNotEmpty()) {
                $audit->record($request->user(), 'survey.feeders_assigned', $team, new: [
                    'scope' => $scope,
                    'feeder_ids' => $newFeeders->modelKeys(),
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                ]);
            }

            return [$newFeeders->count(), $feeders->count() - $newFeeders->count(), $team->name];
        }, 3);

        return back()->with('success', "{$created} feeder(s) assigned to {$teamName}. {$skipped} already assigned to this team.");
    }
}
