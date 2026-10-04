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
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.teams.index', [
            'surveyTeams' => SurveyTeam::with('members')->get(), 'mdbTeams' => MdbTeam::with('members')->get(),
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
        $data = $request->validate(['type' => ['required', 'in:survey,mdb'], 'team_id' => ['required', 'integer'], 'user_id' => ['required', 'exists:users,id'], 'is_leader' => ['nullable', 'boolean']]);
        $team = match ($data['type']) {
            'survey' => SurveyTeam::findOrFail($data['team_id']), 'mdb' => MdbTeam::findOrFail($data['team_id'])
        };
        $team->members()->syncWithoutDetaching([$data['user_id'] => $data['type'] === 'survey' ? ['is_leader' => $request->boolean('is_leader')] : []]);

        return back()->with('success', 'Team member assigned.');
    }

    public function assignFeeder(Request $request): RedirectResponse
    {
        $data = $request->validate(['feeder_id' => ['required', 'exists:feeders,id'], 'survey_team_id' => ['required', 'exists:survey_teams,id'], 'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'remarks' => ['nullable', 'string', 'max:1000']]);
        FeederAssignment::create($data + ['assigned_by' => $request->user()->id, 'status' => 'active']);

        return back()->with('success', 'Feeder assigned to survey team.');
    }
}
