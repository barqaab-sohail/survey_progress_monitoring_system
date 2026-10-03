<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Division;
use App\Models\GridStation;
use App\Models\Project;
use App\Models\SubDivision;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HierarchyController extends Controller
{
    public function index(): View
    {
        return view('admin.master.hierarchy', [
            'projects' => Project::withCount('feeders')->orderBy('name')->get(),
            'circles' => Circle::with('project')->orderBy('name')->get(),
            'divisions' => Division::with('circle')->orderBy('name')->get(),
            'subDivisions' => SubDivision::with('division')->orderBy('name')->get(),
            'grids' => GridStation::with('subDivision')->orderBy('name')->get(),
        ]);
    }

    public function project(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'unique:projects,code'], 'name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone'], 'processing_required' => ['nullable', 'boolean']]);
        $data['processing_required'] = $request->boolean('processing_required');
        $project = Project::create($data + ['status' => 'active']);
        $audit->record($request->user(), 'project.created', $project, new: $project->toArray());

        return back()->with('success', 'Project created.');
    }

    public function circle(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id'], 'code' => ['required', 'string', 'max:50', Rule::unique('circles')->where('project_id', $request->project_id)], 'name' => ['required', 'string', 'max:255']]);
        $record = Circle::create($data);
        $audit->record($request->user(), 'circle.created', $record, new: $record->toArray());

        return back()->with('success', 'Circle created.');
    }

    public function division(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id'], 'circle_id' => ['required', Rule::exists('circles', 'id')->where('project_id', $request->project_id)], 'code' => ['required', 'string', 'max:50', Rule::unique('divisions')->where('project_id', $request->project_id)], 'name' => ['required', 'string', 'max:255']]);
        $record = Division::create($data);
        $audit->record($request->user(), 'division.created', $record, new: $record->toArray());

        return back()->with('success', 'Division created.');
    }

    public function subDivision(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id'], 'division_id' => ['required', Rule::exists('divisions', 'id')->where('project_id', $request->project_id)], 'code' => ['required', 'string', 'max:50', Rule::unique('sub_divisions')->where('project_id', $request->project_id)], 'name' => ['required', 'string', 'max:255']]);
        $record = SubDivision::create($data);
        $audit->record($request->user(), 'sub_division.created', $record, new: $record->toArray());

        return back()->with('success', 'Sub-division created.');
    }

    public function gridStation(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id'], 'sub_division_id' => ['required', Rule::exists('sub_divisions', 'id')->where('project_id', $request->project_id)], 'code' => ['required', 'string', 'max:50', Rule::unique('grid_stations')->where('project_id', $request->project_id)], 'name' => ['required', 'string', 'max:255']]);
        $record = GridStation::create($data);
        $audit->record($request->user(), 'grid_station.created', $record, new: $record->toArray());

        return back()->with('success', 'Grid station created.');
    }
}
