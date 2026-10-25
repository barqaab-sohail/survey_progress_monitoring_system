<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SurveyorAssignment;
use App\Models\User;
use App\Services\AssignmentChoices;
use App\Services\AssignmentOverview;
use App\Services\UserAssignmentManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserAssignmentController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate([
            'user_id' => 'nullable|integer|exists:users,id', 'task_type' => ['nullable', Rule::in(array_keys(UserAssignmentManager::TASKS))],
            'type' => ['nullable', Rule::in(array_keys(AssignmentOverview::TYPES))], 'status' => 'nullable|string|max:60',
            'project_id' => 'nullable|integer', 'search' => 'nullable|string|max:100',
            'task_search' => 'nullable|string|max:100', 'task_project' => 'nullable|integer',
            'survey_team_id' => 'nullable|integer', 'choice_page' => 'nullable|integer|min:1|max:100000',
        ]);
    }

    public function index(Request $request, AssignmentOverview $overview, UserAssignmentManager $manager, AssignmentChoices $choices)
    {
        $filters = $this->filters($request);
        $users = User::with('roles')->orderBy('name')->get();
        $selected = isset($filters['user_id']) ? $users->firstWhere('id', $filters['user_id']) : null;
        $eligible = $selected ? array_filter(UserAssignmentManager::TASKS, fn ($type) => $manager->eligible($selected, $type), ARRAY_FILTER_USE_KEY) : [];
        $taskType = $filters['task_type'] ?? array_key_first($eligible);
        $records = $counts = $statuses = null;
        if ($selected) {
            $base = $overview->query($selected);
            $counts = (clone $base)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
            $statuses = (clone $base)->distinct()->orderBy('status')->pluck('status');
            $records = $base->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['project_id'] ?? null, fn ($q, $project) => $q->where('project_id', $project))
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")->orWhere('resource_id', $search)))
                ->orderByDesc('assigned_at')->orderBy('type')->orderByDesc('record_id')->paginate(25)->withQueryString();
        }
        $projects = Project::orderBy('name')->get(['id', 'name']);
        $teams = $selected?->surveyTeams()->with('project')->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get() ?? collect();
        $filters['survey_team_id'] ??= $teams->first()?->id;
        $options = $selected && $taskType ? $choices->get($selected, $taskType, $filters) : ['items' => [], 'more' => false];

        return view('admin.assignments.index', compact('users', 'selected', 'eligible', 'taskType', 'records', 'counts', 'statuses', 'projects', 'teams', 'options', 'filters'));
    }

    public function options(Request $request, AssignmentChoices $choices)
    {
        $filters = $this->filters($request);
        abort_unless(isset($filters['user_id'], $filters['task_type']), 422);

        return response()->json($choices->get(User::findOrFail($filters['user_id']), $filters['task_type'], $filters));
    }

    public function candidates(SurveyorAssignment $assignment)
    {
        $users = $assignment->team->members()->with('roles')->where('status', 'active')->where('users.id', '!=', $assignment->surveyor_id)->orderBy('name')->get()
            ->filter(fn ($user) => $user->hasAnyRole(['surveyor', 'survey_team_leader']))->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values();

        return response()->json(['users' => $users]);
    }

    public function store(Request $request, UserAssignmentManager $manager)
    {
        try {
            $message = $manager->assign($request);
        } catch (ValidationException $exception) {
            $exception->redirectTo = route('admin.assignments.index', array_filter(['user_id' => $request->input('user_id'), 'task_type' => $request->input('task_type') === 'mobile_reassign' ? 'mobile' : $request->input('task_type')]));
            throw $exception;
        }

        return redirect()->route('admin.assignments.index', ['user_id' => $request->input('user_id'), 'task_type' => $request->input('task_type') === 'mobile_reassign' ? 'mobile' : $request->input('task_type')])->with('success', $message);
    }
}
