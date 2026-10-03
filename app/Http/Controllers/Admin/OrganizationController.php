<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        return view('admin.organizations.index', ['organizations' => Organization::withCount('users')->orderBy('name')->get()]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:organizations,name'], 'type' => ['required', 'in:internal,third_party'], 'contact_person' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:1000']]);
        $organization = Organization::create($data + ['status' => 'active']);
        $audit->record($request->user(), 'organization.created', $organization, new: $organization->toArray());

        return back()->with('success', 'Organization created.');
    }

    public function update(Request $request, Organization $organization, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('organizations')->ignore($organization)], 'type' => ['required', 'in:internal,third_party'], 'contact_person' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:1000'], 'status' => ['required', 'in:active,inactive']]);
        $old = $organization->toArray();
        $organization->update($data);
        $audit->record($request->user(), 'organization.updated', $organization, $old, $organization->fresh()->toArray());

        return back()->with('success', 'Organization updated.');
    }
}
