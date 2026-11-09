<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::with(['organization', 'surveyTeams'])->where(fn ($query) => $query
            ->where('role', '!=', UserRole::MdbProcessingUser->value)
            ->orWhereDoesntHave('organization', fn ($organization) => $organization->where('type', 'internal')))
            ->orderBy('name')->get();

        return view('admin.users.index', ['users' => $users, 'organizations' => Organization::where('status', 'active')->orderBy('name')->get(), 'roles' => UserRole::cases(),
            'mobileTeams' => SurveyTeam::with('project')->where('status', 'active')->whereNotNull('group_code')->whereHas('project', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get()]);
    }

    public function store(Request $request, AuditService $audit, UserPhotoService $photos): RedirectResponse
    {
        $data = $request->validate(['organization_id' => $this->organizationRules($request), 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', Rule::enum(UserRole::class)], 'allow_voice_form_filling' => ['sometimes', 'boolean'], 'password' => ['required', 'string', 'min:10', 'confirmed']]);
        $request->validate(UserPhotoService::rules());
        $team = $this->mobileTeam($request);
        $user = DB::transaction(function () use ($request, $data, $photos, $team) {
            $user = User::create($data + ['status' => 'active'] + $photos->attributes($request));
            if ($team) {
                $user->surveyTeams()->sync([$team->id => ['is_leader' => false]]);
            }

            return $user;
        });
        $audit->record($request->user(), 'user.created', $user, new: collect($user->toArray())->except('password')->all());

        return back()->with('success', 'User account created.');
    }

    public function update(Request $request, User $user, AuditService $audit, UserPhotoService $photos): RedirectResponse
    {
        $data = $request->validate(['organization_id' => $this->organizationRules($request), 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($user)], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', Rule::enum(UserRole::class)], 'allow_voice_form_filling' => ['sometimes', 'boolean'], 'status' => ['required', 'in:active,inactive'], 'password' => ['nullable', 'string', 'min:10', 'confirmed']]);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $request->validate(UserPhotoService::rules());
        $old = collect($user->toArray())->except('password')->all();
        $team = $this->mobileTeam($request);
        DB::transaction(function () use ($request, $data, $photos, $team, $user) {
            $user->update($data + $photos->attributes($request));
            if ($team) {
                $user->surveyTeams()->sync([$team->id => ['is_leader' => false]]);
            }
        });
        $audit->record($request->user(), 'user.updated', $user, $old, collect($user->fresh()->toArray())->except('password')->all());

        return back()->with('success', 'User account updated.');
    }

    private function organizationRules(Request $request): array
    {
        if ($request->input('role') === UserRole::MdbProcessingUser->value) {
            return ['required', Rule::exists('organizations', 'id')->where('type', 'third_party')->where('status', 'active')->whereNull('deleted_at')];
        }

        return ['nullable', 'exists:organizations,id'];
    }

    private function mobileTeam(Request $request): ?SurveyTeam
    {
        if ($request->input('role') !== UserRole::MobileSurveyUser->value) {
            return null;
        }
        $data = $request->validate(['mobile_survey_team_id' => ['required', 'integer']]);
        $team = SurveyTeam::where('status', 'active')->whereNotNull('group_code')
            ->whereHas('project', fn ($q) => $q->where('status', 'active'))->find($data['mobile_survey_team_id']);
        if (! $team || ! preg_match('/^[0-9]{2}$/D', $team->group_code)) {
            throw ValidationException::withMessages(['mobile_survey_team_id' => 'Select an active survey team with an explicit two-digit group code. Configure it under Teams & Assignments.']);
        }

        return $team;
    }
}
