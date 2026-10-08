<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTransformerMdbRequest;
use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\TransformerMdbProject;
use App\Services\AuditService;
use App\Services\GpxWaypointService;
use App\Services\TransformerMdbNetworkService;
use App\Services\TransformerMdbWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TransformerMdbBuilderController extends Controller
{
    public function index(Request $request)
    {
        $projects = TransformerMdbProject::with(['feeder', 'creator'])->latest()->paginate(20);
        $surveys = FieldSurvey::with('feeder')->where('status', 'submitted')->latest()->limit(100)->get();

        return view('mdb-builder.index', compact('projects', 'surveys'));
    }

    public function create(Request $request)
    {
        $this->editor($request);
        $request->validate(['source' => ['nullable', 'integer', 'exists:field_surveys,id']]);
        $project = new TransformerMdbProject(['revision' => 0, 'survey_date' => today(), 'header' => [], 'rows' => [], 'solar' => [], 'export_settings' => $this->settings()]);
        if ($request->filled('source')) {
            $survey = FieldSurvey::where('status', 'submitted')->findOrFail($request->integer('source'));
            $project->fill($survey->only(['feeder_id', 'transformer_code', 'survey_date', 'header', 'rows', 'solar', 'remarks']));
            $project->source_field_survey_id = $survey->id;
        }

        return $this->form($project);
    }

    public function edit(Request $request, TransformerMdbProject $project)
    {
        $this->editor($request);

        return $this->form($project);
    }

    private function form(TransformerMdbProject $project)
    {
        $feeders = Feeder::active()->whereHas('project', fn ($query) => $query->where('status', 'active'))->with(['gridStation', 'division', 'subDivision', 'transformers' => fn ($query) => $query->select(['id', 'feeder_id', 'transformer_code', 'gps_waypoint_number', 'capacity_kva', 'equipment_make', 'equipment_location', 'latitude', 'longitude'])])->orderBy('feeder_code')->get();

        return view('mdb-builder.form', compact('project', 'feeders'));
    }

    public function store(SaveTransformerMdbRequest $request, GpxWaypointService $gpx, AuditService $audit)
    {
        return $this->save($request, new TransformerMdbProject, $gpx, $audit);
    }

    public function update(SaveTransformerMdbRequest $request, TransformerMdbProject $project, GpxWaypointService $gpx, AuditService $audit)
    {
        return $this->save($request, $project, $gpx, $audit);
    }

    private function save(SaveTransformerMdbRequest $request, TransformerMdbProject $project, GpxWaypointService $gpx, AuditService $audit)
    {
        $data = $request->validated();
        abort_unless(Feeder::active()->whereHas('project', fn ($query) => $query->where('status', 'active'))->whereKey($data['feeder_id'])->exists(), 422, 'Select an active feeder in an active project.');
        $waypoints = $request->hasFile('gpx') ? $gpx->read($request->file('gpx')->getRealPath()) : null;
        $newPaths = [];
        $oldPaths = [];
        try {
            foreach (['pdf', 'gpx'] as $key) {
                if ($request->hasFile($key)) {
                    $path = $request->file($key)->store('mdb-builder/sources', 'local');
                    if (! $path) {
                        throw new RuntimeException('The uploaded file could not be stored.');
                    }
                    $newPaths[$key.'_path'] = $path;
                }
            }
            $saved = DB::transaction(function () use ($request, $project, $data, $waypoints, $newPaths, &$oldPaths, $audit) {
                $existing = $project->exists;
                if ($existing) {
                    $project = TransformerMdbProject::lockForUpdate()->findOrFail($project->id);
                }
                if ((int) $data['revision'] !== ($existing ? $project->revision : 0)) {
                    throw ValidationException::withMessages(['revision' => 'This workspace changed in another tab. Reload it before saving to avoid overwriting changes.']);
                }
                foreach ($newPaths as $key => $path) {
                    if ($project->$key) {
                        $oldPaths[] = $project->$key;
                    }
                }
                $old = $project->exists ? $project->toArray() : [];
                $sourceId = $existing ? $project->source_field_survey_id : ($data['source_field_survey_id'] ?? null);
                if ($sourceId) {
                    $source = FieldSurvey::where('status', 'submitted')->findOrFail($sourceId);
                    if ((int) $source->feeder_id !== (int) $data['feeder_id']) {
                        throw ValidationException::withMessages(['feeder_id' => 'A workspace copied from Android must keep its source feeder. Create a separate manual workspace for another feeder.']);
                    }
                }
                $project->fill(collect($data)->only(['feeder_id', 'transformer_code', 'survey_date', 'header', 'rows', 'solar', 'remarks', 'export_settings'])->all());
                $project->fill($newPaths);
                $project->created_by = $existing ? $project->created_by : $request->user()->id;
                $project->source_field_survey_id = $sourceId;
                $project->revision = $existing ? $project->revision + 1 : 1;
                if ($waypoints !== null) {
                    $project->gpx_waypoints = $waypoints;
                }
                $project->save();
                $audit->record($request->user(), $existing ? 'mdb_workspace.updated' : 'mdb_workspace.created', $project, $old, $project->toArray());

                return $project;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($newPaths));
            throw $exception;
        }
        Storage::disk('local')->delete($oldPaths);

        return redirect()->route('mdb-builder.edit', $saved)->with('success', 'Transformer survey saved. Review the GPX links and network before creating MDB.');
    }

    public function preview(Request $request, TransformerMdbProject $project, TransformerMdbNetworkService $builder)
    {
        $network = null;
        $problems = [];
        try {
            $network = $builder->build($project);
        } catch (ValidationException $exception) {
            $problems = $exception->errors();
        }

        return view('mdb-builder.preview', compact('project', 'network', 'problems'));
    }

    public function export(Request $request, TransformerMdbProject $project, TransformerMdbNetworkService $builder, TransformerMdbWriter $writer, AuditService $audit)
    {
        $this->editor($request);
        $request->validate(['revision' => ['required', 'integer']]);
        abort_unless($request->integer('revision') === $project->revision, 409, 'Workspace changed. Review the latest revision before export.');
        $network = $builder->build($project);
        try {
            $path = $writer->write($network);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['export' => $exception->getMessage()]);
        }
        try {
            $audit->record($request->user(), 'mdb_workspace.exported', $project, new: ['revision' => $project->revision, 'nodes' => $network['node_count'], 'sections' => $network['section_count']]);
        } catch (Throwable $exception) {
            File::deleteDirectory(dirname($path));
            throw $exception;
        }
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $project->feeder->feeder_name.'-'.$project->transformer_code).'.mdb';

        return response()->download($path, $name, ['Content-Type' => 'application/x-msaccess', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function source(Request $request, TransformerMdbProject $project, string $kind)
    {
        abort_unless(in_array($kind, ['pdf', 'gpx'], true), 404);
        $path = $project->{$kind.'_path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => $kind === 'pdf' ? 'application/pdf' : 'application/gpx+xml',
            'Content-Disposition' => ($kind === 'pdf' ? 'inline' : 'attachment').'; filename="survey.'.$kind.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function editor(Request $request): void
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
    }

    private function settings(): array
    {
        return ['utm_zone' => 43, 'frequency' => 50, 'nominal_kv' => 11, 'transformer_waypoints' => '', 'transformer_latitude' => null, 'transformer_longitude' => null,
            'transformer_type' => '', 'configuration_id' => '12.5/7.2 kV cross arm C2-2', 'phase_spacing_cm' => 121.9, 'neutral_spacing_cm' => 91.4,
            'conductor_height_m' => 9.1, 'consumer_kva' => [], 'blank_consumers_zero' => false, 'engineering_reviewed' => false];
    }
}
