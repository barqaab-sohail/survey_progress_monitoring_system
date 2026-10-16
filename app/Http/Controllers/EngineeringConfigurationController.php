<?php

namespace App\Http\Controllers;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Services\Mdb\EngineeringConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EngineeringConfigurationController extends Controller
{
    public function index()
    {
        Gate::authorize('mdb-workflow.configure');

        return view('engineering.index', ['projects' => Project::orderBy('name')->get(),
            'configurations' => Configuration::all()->keyBy('project_id')]);
    }

    public function edit(Project $project)
    {
        Gate::authorize('mdb-workflow.configure');

        return view('engineering.edit', ['project' => $project,
            'configuration' => Configuration::where('project_id', $project->id)->first(),
            'templates' => Template::where('active', true)->whereNotNull('approved_by')->whereNotNull('approved_at')->get()]);
    }

    public function update(Request $request, Project $project, EngineeringConfiguration $engineering)
    {
        Gate::authorize('mdb-workflow.configure');
        if ($request->input('save_action') === 'draft') {
            $engineering->saveDraft($project, $request);

            return back()->with('success', 'Engineering draft saved. Unresolved settings remain blank; Admin approval is required before MDB generation. Affected survey approvals must be renewed.');
        }
        $engineering->save($project, $request);

        return back()->with('success', 'Engineering configuration saved centrally. All MDBs for this project use this revision. Affected survey approvals must be renewed.');
    }
}
