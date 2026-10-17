<?php

namespace App\Http\Controllers;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Services\AuditService;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\SchemaTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

            return back()->with('success', 'Engineering draft saved. Unresolved settings remain blank; Admin approval is required for full engineering validation mode. Affected survey approvals must be renewed.');
        }
        $engineering->save($project, $request);

        return back()->with('success', 'Engineering configuration saved centrally. All MDBs for this project use this revision. Affected survey approvals must be renewed.');
    }

    public function updateMode(Request $request, Project $project, AuditService $audit)
    {
        Gate::authorize('mdb-workflow.configure');
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'simple_mdb_generation' => 'required|boolean',
        ]);

        DB::transaction(function () use ($request, $project, $audit, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $configuration = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if (($configuration?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Another Admin updated these settings. Reload before saving.']);
            }

            $before = $configuration?->toArray() ?? [];
            $configuration ??= new Configuration(['project_id' => $project->id]);
            $settings = $configuration->settings ?? [];
            if (array_key_exists('simple_mdb_generation', $settings) && $settings['simple_mdb_generation'] === (bool) $data['simple_mdb_generation']) {
                return;
            }
            $settings['simple_mdb_generation'] = (bool) $data['simple_mdb_generation'];
            if ($settings['simple_mdb_generation'] && empty($settings['template_id'])) {
                app(SchemaTemplate::class)->ensure($request->user());
            }
            $configuration->fill(['settings' => $settings, 'revision' => ($configuration->revision ?? 0) + 1])->save();
            $audit->record($request->user(), 'mdb.generation_mode_changed', $configuration, $before, $configuration->toArray(),
                $settings['simple_mdb_generation'] ? 'Admin enabled Simple MDB Generation.' : 'Admin disabled Simple MDB Generation.');
        });

        return back()->with('success', 'Simple MDB Generation setting saved. Affected survey approvals must be renewed.');
    }
}
