<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::with('organization')->where(fn ($query) => $query
            ->where('role', '!=', UserRole::MdbProcessingUser->value)
            ->orWhereDoesntHave('organization', fn ($organization) => $organization->where('type', 'internal')))
            ->orderBy('name')->get();

        return view('admin.users.index', ['users' => $users, 'organizations' => Organization::where('status', 'active')->orderBy('name')->get(), 'roles' => UserRole::cases()]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['organization_id' => $this->organizationRules($request), 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', Rule::enum(UserRole::class)], 'password' => ['required', 'string', 'min:10', 'confirmed']]);
        $user = User::create($data + ['status' => 'active']);
        $audit->record($request->user(), 'user.created', $user, new: collect($user->toArray())->except('password')->all());

        return back()->with('success', 'User account created.');
    }

    public function update(Request $request, User $user, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['organization_id' => $this->organizationRules($request), 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($user)], 'phone' => ['nullable', 'string', 'max:30'], 'role' => ['required', Rule::enum(UserRole::class)], 'status' => ['required', 'in:active,inactive'], 'password' => ['nullable', 'string', 'min:10', 'confirmed']]);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $old = collect($user->toArray())->except('password')->all();
        $user->update($data);
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
}
