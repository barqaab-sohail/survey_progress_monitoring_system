<?php

namespace App\Http\Controllers;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\EngineeringPreset;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Services\AuditService;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\EngineeringDefaults;
use App\Services\Mdb\GenerationMode;
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
        $configuration = app(EngineeringDefaults::class)->preview($project);
        $schemaId = GenerationMode::simple($configuration) ? app(SchemaTemplate::class)->current()?->id : null;

        return view('engineering.edit', ['project' => $project,
            'configuration' => $configuration,
            'templates' => Template::where('active', true)->where(function ($query) use ($schemaId) {
                $query->where(fn ($approved) => $approved->whereNotNull('approved_by')->whereNotNull('approved_at'));
                if ($schemaId) {
                    $query->orWhere('id', $schemaId);
                }
            })->get()]);
    }

    public function update(Request $request, Project $project, EngineeringConfiguration $engineering)
    {
        Gate::authorize('mdb-workflow.configure');
        if ($request->input('save_action') === 'simple') {
            $request->merge(['confirm_simple_model' => 1]);
        }
        if (in_array($request->input('save_action'), ['draft', 'simple'], true)) {
            $engineering->saveDraft($project, $request);

            return back()->with('success', 'Engineering draft saved. Unresolved settings remain blank; Admin approval is required for full engineering validation mode. Affected survey approvals must be renewed.');
        }
        $engineering->save($project, $request);

        return back()->with('success', 'Engineering configuration saved centrally. All MDBs for this project use this revision. Affected survey approvals must be renewed.');
    }

    public function library(EngineeringDefaults $defaults)
    {
        Gate::authorize('mdb-workflow.configure');

        return view('engineering.library', ['preset' => $defaults->library()]);
    }

    public function updateLibrary(Request $request, EngineeringDefaults $defaults, AuditService $audit)
    {
        Gate::authorize('mdb-workflow.configure');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'name' => 'required|string|max:255',
            'enabled' => 'required|boolean', 'configuration_json' => 'required|json|max:262144', 'reason' => 'required|string|min:5|max:4000']);
        $payload = $defaults->validate($data['configuration_json']);
        DB::transaction(function () use ($request, $data, $payload, $audit) {
            $preset = EngineeringPreset::where('code', EngineeringDefaults::CODE)->lockForUpdate()->firstOrFail();
            if ($preset->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Another Admin updated this library. Reload before saving.']);
            }
            $before = $preset->toArray();
            $preset->fill(['name' => $data['name'], 'enabled' => $data['enabled'], 'configuration' => $payload,
                'revision' => $preset->revision + 1])->save();
            $audit->record($request->user(), 'mdb.engineering_library_updated', $preset, $before, $preset->toArray(), $data['reason']);
        });

        return back()->with('success', 'Engineering library updated. Future project configurations use these defaults; existing project settings retain their values.');
    }

    public function updateMode(Request $request, Project $project, AuditService $audit)
    {
        Gate::authorize('mdb-workflow.configure');
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'simple_mdb_generation' => 'required|boolean',
            'use_system_schema' => 'sometimes|boolean',
        ]);
        if (! empty($data['use_system_schema']) && ! $data['simple_mdb_generation']) {
            throw ValidationException::withMessages(['use_system_schema' => 'Enable Simple MDB Generation to select the built-in clean schema.']);
        }

        DB::transaction(function () use ($request, $project, $audit, $data) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $configuration = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if (($configuration?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Another Admin updated these settings. Reload before saving.']);
            }

            $before = $configuration?->toArray() ?? [];
            $configuration ??= new Configuration(['project_id' => $project->id]);
            $settings = $configuration->settings ?? [];
            if (! empty($data['use_system_schema'])) {
                $schema = app(SchemaTemplate::class)->ensure($request->user());
                if (! $schema->active) {
                    throw ValidationException::withMessages(['use_system_schema' => 'The built-in clean schema is inactive. Restore its active status before selecting it.']);
                }
                $settings['template_id'] = $schema->id;
            }
            if ($configuration->exists && $configuration->settings === array_replace($settings, ['simple_mdb_generation' => (bool) $data['simple_mdb_generation']])) {
                return;
            }
            $settings['simple_mdb_generation'] = (bool) $data['simple_mdb_generation'];
            if ($settings['simple_mdb_generation'] && empty($settings['template_id'])) {
                app(SchemaTemplate::class)->ensure($request->user());
            }
            $configuration->fill(['settings' => $settings, 'revision' => ($configuration->revision ?? 0) + 1])->save();
            $audit->record($request->user(), 'mdb.generation_mode_changed', $configuration, $before, $configuration->toArray(),
                ! empty($data['use_system_schema']) ? 'Admin selected the built-in clean MDB schema for Simple generation.' : ($settings['simple_mdb_generation'] ? 'Admin enabled Simple MDB Generation.' : 'Admin disabled Simple MDB Generation.'));
        });

        return back()->with('success', 'Simple MDB Generation setting saved. Affected survey approvals must be renewed.');
    }
}
