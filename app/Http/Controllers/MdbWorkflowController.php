<?php

namespace App\Http\Controllers;

use App\Jobs\Mdb\ProcessSourceFile;
use App\Models\Feeder;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\Consumer;
use App\Models\Mdb\ExportJob;
use App\Models\Mdb\ModelValidation;
use App\Models\Mdb\NetworkSection;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\PvRecord;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Mdb\VerificationDecision;
use App\Models\Mdb\Waypoint;
use App\Models\Project;
use App\Models\SurveyTeam;
use App\Services\AuditService;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\ExporterFactory;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SourceService;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MdbWorkflowController extends Controller
{
    public function __construct(private WorkflowAccess $access, private SnapshotService $snapshots, private AuditService $audit) {}

    public function index(Request $request)
    {
        Gate::authorize('mdb-workflow.view');
        $filters = $request->validate(['project_id' => 'nullable|integer', 'feeder_id' => 'nullable|integer', 'survey_team_id' => 'nullable|integer',
            'survey_date' => 'nullable|date', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from', 'transformer' => 'nullable|string|max:255', 'status' => 'nullable|string|max:40']);
        $query = $this->access->visible($request->user());
        foreach (['project_id', 'feeder_id', 'survey_team_id', 'survey_date', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['transformer'])) {
            $query->whereHas('transformers', fn ($q) => $q->where('code', 'like', '%'.$filters['transformer'].'%'));
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('survey_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('survey_date', '<=', $filters['date_to']);
        }
        $ids = (clone $query)->pluck('id');
        $transformers = NetworkTransformer::whereIn('batch_id', $ids);
        $metrics = [
            'surveyed_transformers' => (clone $transformers)->count(),
            'sections_entered' => NetworkSection::whereIn('transformer_id', (clone $transformers)->select('id'))->count(),
            'validation_errors' => 0,
            'awaiting_verification' => (clone $query)->where('status', 'awaiting_verification')->count(),
            'approved_transformers' => (clone $transformers)->whereHas('batch', fn ($q) => $q->whereNotNull('approved_revision_id'))->count(),
            'export_failures' => ExportJob::whereIn('batch_id', $ids)->whereIn('status', ['failed', 'worker_not_configured'])->count(),
            'mdb_generated' => ExportJob::whereIn('batch_id', $ids)->where('status', 'generated')->whereNull('superseded_at')->count(),
            'analysis_accepted' => ModelValidation::where('status', 'accepted')->whereIn('id', ModelValidation::selectRaw('MAX(id)')->groupBy('export_job_id'))
                ->whereHas('exportJob', fn ($q) => $q->whereIn('batch_id', $ids)->where('status', 'generated')->whereNull('superseded_at'))->count(),
        ];
        $templateId = Template::where('active', true)->whereNotNull('approved_at')->latest('id')->value('id') ?? 0;
        foreach ((clone $query)->get() as $record) {
            $metrics['validation_errors'] += Cache::remember('mdb-validation:'.$record->id.':'.$record->revision.':'.$templateId, 300,
                fn () => count(app(NetworkValidator::class)->validate($record)['errors']));
        }

        return view('mdb-workflow.index', ['batches' => $query->with(['project', 'feeder', 'surveyTeam'])->withCount(['transformers', 'sources', 'exports'])->latest()->paginate(20)->withQueryString(),
            'metrics' => $metrics, 'filters' => $filters] + $this->references($request));
    }

    public function create(Request $request)
    {
        Gate::authorize('mdb-workflow.upload');

        return view('mdb-workflow.create', $this->references($request));
    }

    public function store(Request $request)
    {
        Gate::authorize('mdb-workflow.upload');
        $data = $request->validate(['project_id' => 'required|exists:projects,id', 'feeder_id' => 'required|exists:feeders,id',
            'survey_team_id' => 'required|exists:survey_teams,id', 'survey_date' => 'required|date', 'files' => 'nullable|array|max:20', 'files.*' => 'file']);
        $team = SurveyTeam::findOrFail($data['survey_team_id']);
        $feeder = Feeder::active()->findOrFail($data['feeder_id']);
        abort_unless($team->status === 'active' && $team->project_id === (int) $data['project_id'] && $feeder->project_id === (int) $data['project_id'], 422, 'Team, feeder and project must match.');
        if (! $request->user()->hasRole('super_admin')) {
            abort_unless($team->members()->whereKey($request->user()->id)->exists(), 403);
            abort_unless($feeder->assignments()->where('survey_team_id', $team->id)->where('status', 'active')->whereDate('start_date', '<=', today())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->exists(), 403);
        }
        $batch = DB::transaction(function () use ($data, $request) {
            $batch = SurveyBatch::create(array_intersect_key($data, array_flip(['project_id', 'feeder_id', 'survey_team_id', 'survey_date'])) + ['created_by' => $request->user()->id, 'status' => 'draft']);
            foreach ($request->file('files', []) as $file) {
                app(SourceService::class)->store($batch, $request->user(), $file);
            }
            $this->audit->record($request->user(), 'mdb.batch_created', $batch, [], $batch->toArray());

            return $batch;
        });

        return to_route('mdb-workflow.show', $batch)->with('success', 'Batch created. Source processing is queued.');
    }

    public function show(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch);

        return view('mdb-workflow.show', ['batch' => $batch->load(['project', 'feeder', 'surveyTeam']),
            'editorData' => app(EntryService::class)->editorData($batch)]);
    }

    public function review(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch);
        $batch->load(['project', 'feeder', 'surveyTeam', 'transformers.entryRows', 'transformers.sections.consumers', 'exports.transformer', 'exports.revision', 'approvedRevision']);

        return view('mdb-workflow.review', ['batch' => $batch, 'entryIssues' => app(EntryService::class)->issues($batch),
            'validation' => app(NetworkValidator::class)->validate($batch), 'workerConfigured' => ExporterFactory::make()->configured()]);
    }

    public function advanced(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch);
        $batch->load(['project', 'feeder', 'surveyTeam', 'approvedRevision', 'sources.waypoints', 'waypoints', 'transformers.sourceWaypoint', 'transformers.sections.consumers', 'transformers.pvRecords', 'decisions.verifier', 'decisions.revision', 'exports.validations.analyst', 'exports.revision', 'exports.template', 'exports.transformer']);

        return view('mdb-workflow.advanced', ['batch' => $batch, 'validation' => app(NetworkValidator::class)->validate($batch),
            'configuration' => Configuration::where('project_id', $batch->project_id)->first(), 'templates' => Template::where('active', true)->get(),
            'workerConfigured' => ExporterFactory::make()->configured()]);
    }

    public function upload(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch, 'upload');
        $request->validate(['file' => 'required_without:drive_file_id|file', 'drive_file_id' => 'required_without:file|nullable|string|max:200', 'parent_source_id' => 'nullable|integer']);
        $this->mutate($request, $batch, function (SurveyBatch $locked) use ($request) {
            $parent = $request->filled('parent_source_id') ? $locked->sources()->findOrFail($request->input('parent_source_id')) : null;

            return $request->hasFile('file') ? app(SourceService::class)->store($locked, $request->user(), $request->file('file'), $parent)
                : app(SourceService::class)->importDrive($locked, $request->user(), $request->input('drive_file_id'), $parent);
        });

        return back()->with('success', 'Source preserved and queued for processing.');
    }

    public function retrySource(Request $request, SurveyBatch $batch, SourceFile $source)
    {
        $this->access->authorize($request->user(), $batch, 'upload');
        abort_unless($source->batch_id === $batch->id && $source->status === 'failed', 422);
        $this->mutate($request, $batch, function () use ($source) {
            $source->update(['status' => 'queued']);
            ProcessSourceFile::dispatch($source->id)->afterCommit();

            return $source;
        });

        return back()->with('success', 'Source processing retry queued.');
    }

    public function source(Request $request, SurveyBatch $batch, SourceFile $source)
    {
        $this->access->authorize($request->user(), $batch);
        abort_unless($source->batch_id === $batch->id, 404);
        $disk = Storage::disk($source->disk);
        abort_unless($disk->exists($source->path), 404);
        abort_unless(hash_equals($source->sha256, hash_file('sha256', $disk->path($source->path))), 409, 'Original source hash verification failed.');
        $headers = ['Content-Type' => $source->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $request->boolean('download') || $source->kind !== 'pdf' ? $disk->download($source->path, $source->original_name, $headers)
            : $disk->response($source->path, $source->original_name, $headers, 'inline');
    }

    public function saveTransformer(Request $request, SurveyBatch $batch, ?NetworkTransformer $transformer = null)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        if ($transformer) {
            abort_unless($transformer->batch_id === $batch->id, 404);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:255', Rule::unique('mdb_workflow_transformers')->where('batch_id', $batch->id)->ignore($transformer?->id)],
            'capacity_kva' => 'nullable|numeric|min:0.001|max:1000000', 'source_waypoint_id' => 'nullable|integer', 'header' => 'nullable|array', 'original_header' => 'nullable|array']);
        $data['header'] = array_replace($transformer?->header ?? [], $this->sanitizeJson($data['header'] ?? []));
        $data['header']['manually_verified'] = $request->boolean('header.manually_verified');
        // Continuation associations are changed only through the explicit confirmed route.
        unset($data['header']['pages']);
        $data['header']['pages'] = $transformer?->header['pages'] ?? [];
        if (! empty($data['source_waypoint_id'])) {
            $batch->waypoints()->findOrFail($data['source_waypoint_id']);
        }
        $this->mutate($request, $batch, fn ($locked) => $transformer ? tap($transformer)->update($data) : $locked->transformers()->create($data));

        return back()->with('success', 'Transformer header saved. Approval must be renewed after edits.');
    }

    public function saveSection(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, ?NetworkSection $section = null)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && (! $section || $section->transformer_id === $transformer->id), 404);
        $data = $request->validate(['start_reference' => 'nullable|string|max:255', 'end_reference' => 'nullable|string|max:255',
            'start_source_file_id' => 'nullable|integer', 'end_source_file_id' => 'nullable|integer', 'start_waypoint_id' => 'nullable|integer', 'end_waypoint_id' => 'nullable|integer',
            'phases' => 'nullable|array|max:4', 'phases.*' => 'required|in:R,Y,B,N', 'conductors' => 'nullable|array', 'conductors.*' => 'nullable|string|max:255',
            'equipment_type' => 'nullable|string|max:255', 'equipment_ref' => 'nullable|string|max:255', 'pole_class' => 'nullable|string|max:255', 'pole_height' => 'nullable|numeric|min:0|max:200',
            'pole_height_unit' => 'nullable|in:m,ft', 'source_pdf_id' => 'nullable|integer', 'source_page' => 'nullable|integer|min:1', 'source_row' => 'nullable|string|max:60',
            'original_entry' => 'nullable|array', 'geometry_json' => 'nullable|json', 'measured_length_m' => 'nullable|numeric|min:0.001|max:1000000', 'measured_length_reason' => 'nullable|string|max:4000',
            'consumers' => 'nullable|array', 'consumers.*.count' => 'nullable|integer|min:0|max:1000000', 'consumers.*.original_value' => 'nullable|string|max:1000', 'consumers.*.demand' => 'nullable|array']);
        foreach (['start', 'end'] as $endpoint) {
            if (! empty($data[$endpoint.'_source_file_id'])) {
                $batch->sources()->where('kind', 'gpx')->findOrFail($data[$endpoint.'_source_file_id']);
            }
            if (! empty($data[$endpoint.'_waypoint_id'])) {
                $batch->waypoints()->findOrFail($data[$endpoint.'_waypoint_id']);
            }
        }
        if (! empty($data['source_pdf_id'])) {
            $batch->sources()->where('kind', 'pdf')->findOrFail($data['source_pdf_id']);
        }
        $consumers = $data['consumers'] ?? [];
        unset($data['consumers']);
        $data['geometry'] = empty($data['geometry_json']) ? null : json_decode($data['geometry_json'], true, 64, JSON_THROW_ON_ERROR);
        unset($data['geometry_json']);
        $data['length_approved_by'] = null;
        $data['original_entry'] = $this->sanitizeJson($data['original_entry'] ?? []);
        $data['original_entry']['manually_verified'] = $request->boolean('original_entry.manually_verified');
        if ($section && $section->entryRows()->exists()) {
            // The operator rows own endpoints, conductors, counts and per-pole observations.
            // Keep engineering references/geometry/demand editable in the advanced screen.
            foreach (['start_reference', 'end_reference', 'start_source_file_id', 'end_source_file_id', 'start_waypoint_id', 'end_waypoint_id',
                'phases', 'conductors', 'equipment_type', 'pole_class', 'pole_height', 'pole_height_unit', 'source_pdf_id', 'source_page', 'source_row'] as $ownedField) {
                unset($data[$ownedField]);
            }
            $data['original_entry'] = $section->original_entry;
            foreach ($consumers as $category => &$consumer) {
                $stored = $section->consumers()->where('category', $category)->first();
                $consumer['count'] = $stored?->count;
                $consumer['original_value'] = $stored?->original_value;
            }
            unset($consumer);
        }
        if (! empty($data['original_entry']['ditto_copied_from_section_id'])) {
            $batch->transformers()->whereHas('sections', fn ($q) => $q->whereKey($data['original_entry']['ditto_copied_from_section_id']))->firstOrFail();
            $request->validate(['original_entry.ditto_copy_confirmed' => 'accepted']);
            $data['original_entry']['ditto_copy_confirmed'] = true;
        }
        $this->mutate($request, $batch, function () use ($data, $consumers, $transformer, $section) {
            $record = $section ? tap($section)->update($data) : $transformer->sections()->create($data);
            foreach ($consumers as $category => $consumer) {
                if (! in_array($category, ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'], true)) {
                    continue;
                }
                $demand = $this->sanitizeJson($consumer['demand'] ?? []);
                unset($demand['approved_by']);
                if (isset($demand['phase_values'])) {
                    $demand['phase_values'] = array_filter($demand['phase_values'], fn ($values) => is_array($values) && count(array_filter($values, fn ($value) => $value !== null && $value !== '')) > 0);
                }
                $record->consumers()->updateOrCreate(['category' => $category], ['count' => $consumer['count'] ?? null, 'original_value' => $consumer['original_value'] ?? null, 'demand' => $demand ?: null]);
            }

            return $record;
        });

        return back()->with('success', 'Survey row saved with original values and explicit endpoints.');
    }

    public function associatePage(Request $request, SurveyBatch $batch, NetworkTransformer $transformer)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id, 404);
        $data = $request->validate(['source_pdf_id' => 'required|integer', 'page' => 'required|integer|min:1', 'role' => 'required|in:header,continuation,pv', 'confirmed' => 'accepted']);
        $pdf = $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->findOrFail($data['source_pdf_id']);
        abort_unless($data['page'] <= ($pdf->metadata['page_count'] ?? 0), 422, 'Page is outside this PDF.');
        $this->mutate($request, $batch, function () use ($transformer, $data) {
            $header = $transformer->header ?? [];
            $header['pages'][] = ['source_pdf_id' => (int) $data['source_pdf_id'], 'page' => (int) $data['page'], 'role' => $data['role'], 'confirmed' => true];
            $transformer->update(['header' => $header]);

            return $transformer;
        });

        return $request->expectsJson()
            ? response()->json(app(EntryService::class)->editorData($batch->fresh()))
            : back()->with('success', 'Continuation page explicitly associated with this transformer header.');
    }

    public function savePv(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, ?PvRecord $pv = null)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && (! $pv || $pv->transformer_id === $transformer->id), 404);
        if ($pv?->entry_row_id) {
            throw ValidationException::withMessages(['pv' => 'Edit this PV observation in its S/E row on the operator entry screen.']);
        }
        $data = $request->validate(['section_id' => 'nullable|integer', 'reference' => 'required|string|max:255', 'installed_capacity_kw' => 'nullable|numeric|min:0|max:1000000',
            'remarks' => 'nullable|string|max:10000', 'original_entry' => 'nullable|array']);
        if (! empty($data['section_id'])) {
            $transformer->sections()->findOrFail($data['section_id']);
        }
        if (isset($data['original_entry'])) {
            $data['original_entry']['manually_verified'] = $request->boolean('original_entry.manually_verified');
        }
        $this->mutate($request, $batch, fn () => $pv ? tap($pv)->update($data) : $transformer->pvRecords()->create($data));

        return back()->with('success', 'PV source record saved.');
    }

    public function removePage(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, int $pageIndex)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && isset($transformer->header['pages'][$pageIndex]), 404);
        $request->validate(['reason' => 'required|string|min:5|max:4000', 'confirmed' => 'accepted']);
        $this->mutate($request, $batch, function () use ($transformer, $pageIndex) {
            $header = $transformer->header;
            unset($header['pages'][$pageIndex]);
            $header['pages'] = array_values($header['pages']);
            $header['manually_verified'] = false;
            $transformer->update(['header' => $header]);

            return $transformer;
        }, $request->input('reason'));

        return back()->with('success', 'Incorrect page association removed. Recheck the header and any affected survey rows.');
    }

    public function removeSection(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, NetworkSection $section)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && $section->transformer_id === $transformer->id, 404);
        if ($section->entryRows()->exists()) {
            throw ValidationException::withMessages(['section' => 'Delete individual S/E rows with confirmation on the operator entry screen.']);
        }
        $request->validate(['reason' => 'required|string|min:5|max:4000']);
        $this->mutate($request, $batch, function () use ($section) {
            $section->pvRecords()->update(['section_id' => null]);
            $section->consumers()->delete();
            $section->delete();

            return null;
        }, $request->input('reason'));

        return back()->with('success', 'Section removed from the current network. Previous entries remain in the audit and frozen revisions.');
    }

    public function correctWaypoint(Request $request, SurveyBatch $batch, Waypoint $waypoint)
    {
        $this->access->authorize($request->user(), $batch, 'verify');
        abort_unless($waypoint->batch_id === $batch->id, 404);
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'reason' => 'required|string|min:5|max:4000', 'evidence' => 'required|string|min:3|max:4000']);
        $this->mutate($request, $batch, function () use ($waypoint, $data, $request) {
            $waypoint->update(['correction' => $data, 'correction_approved_by' => $request->user()->id, 'correction_approved_at' => now('UTC')]);

            return $waypoint;
        }, $data['reason']);

        return back()->with('success', 'Verifier-approved correction recorded; original WGS84 coordinates are preserved.');
    }

    public function approveLength(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, NetworkSection $section)
    {
        $this->access->authorize($request->user(), $batch, 'verify');
        abort_unless($transformer->batch_id === $batch->id && $section->transformer_id === $transformer->id && $section->measured_length_m && $section->measured_length_reason, 422);
        $request->validate(['reason' => 'required|string|min:5|max:4000']);
        $this->mutate($request, $batch, fn () => tap($section)->update(['length_approved_by' => $request->user()->id]), $request->input('reason'));

        return back()->with('success', 'Measured-length override approved.');
    }

    public function approveDemand(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, NetworkSection $section, Consumer $consumer)
    {
        $this->access->authorize($request->user(), $batch, 'verify');
        abort_unless($transformer->batch_id === $batch->id && $section->transformer_id === $transformer->id && $consumer->section_id === $section->id, 404);
        $request->validate(['reason' => 'required|string|min:5|max:4000']);
        $demand = $consumer->demand ?? [];
        if (empty($demand['method']) || empty($demand['evidence']) || empty($demand['phase_values'])) {
            throw ValidationException::withMessages(['demand' => 'Enter documented measured/estimated demand, evidence and phase allocation before approval.']);
        }
        $this->mutate($request, $batch, function () use ($consumer, $request, $demand) {
            $consumer->update(['demand' => array_replace($demand, ['approved_by' => $request->user()->id, 'approval_reason' => $request->input('reason'), 'approved_at' => now('UTC')->toIso8601String()])]);

            return $consumer;
        }, $request->input('reason'));

        return back()->with('success', 'Documented demand and phase allocation approved.');
    }

    public function transition(Request $request, SurveyBatch $batch)
    {
        $data = $request->validate(['revision' => 'required|integer', 'action' => 'required|in:submit,review,reject,approve,comment', 'comment' => 'required_if:action,reject,comment|nullable|string|max:10000']);
        $ability = match ($data['action']) {
            'submit' => 'upload', 'review' => 'edit', default => 'verify'
        };
        $this->access->authorize($request->user(), $batch, $ability);
        DB::transaction(function () use ($data, $batch, $request) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertRevision($locked, $data['revision']);
            $allowed = match ($data['action']) {
                'submit' => ['draft', 'returned'], 'review' => ['draft', 'submitted', 'returned'],
                'reject', 'approve' => ['awaiting_verification'],
                'comment' => ['draft', 'submitted', 'returned', 'awaiting_verification', 'approved'],
            };
            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['action' => 'This transition is unavailable from the current workflow status.']);
            }
            $validation = app(NetworkValidator::class)->validate($locked);
            if (in_array($data['action'], ['submit', 'review', 'approve'], true)) {
                app(EntryService::class)->assertComplete($locked);
            }
            if ($data['action'] === 'approve' && ! $validation['valid']) {
                throw ValidationException::withMessages(['validation' => 'Resolve all critical validation errors before approval.']);
            }
            if ($data['action'] === 'submit' && (! $locked->sources()->exists() || $locked->sources()->where('status', '!=', 'ready')->exists())) {
                throw ValidationException::withMessages(['sources' => 'Upload sources and wait for successful processing before submission.']);
            }
            $revision = $this->snapshots->capture($locked, $request->user()->id);
            VerificationDecision::create(['batch_id' => $locked->id, 'revision_id' => $revision->id, 'decided_by' => $request->user()->id,
                'decision' => $data['action'], 'comments' => $data['comment'] ?? null, 'validation' => $validation]);
            $status = match ($data['action']) {
                'submit' => 'submitted', 'review' => 'awaiting_verification', 'reject' => 'returned', 'approve' => 'approved', 'comment' => $locked->status
            };
            $locked->update(['status' => $status, 'approved_revision_id' => $data['action'] === 'comment' ? $locked->approved_revision_id : ($data['action'] === 'approve' ? $revision->id : null)]);
            $this->audit->record($request->user(), 'mdb.'.$data['action'], $locked, [], ['revision_id' => $revision->id, 'status' => $status], $data['comment'] ?? null);
        });

        return back()->with('success', 'Workflow decision recorded against the immutable revision.');
    }

    public function export(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch, 'export');
        $request->validate(['revision' => 'required|integer', 'transformer_id' => 'nullable|integer', 'template_id' => 'nullable|integer']);
        $this->assertRevision($batch, (int) $request->input('revision'));
        if ($request->filled('template_id') && (int) $request->input('template_id') !== ($batch->approvedRevision?->snapshot['template']['id'] ?? null)) {
            throw ValidationException::withMessages(['template_id' => 'Use the template frozen in the approved revision; changing templates requires a new approval.']);
        }
        $export = app(ExportService::class)->request($batch, $request->user(), $request->filled('transformer_id') ? (int) $request->input('transformer_id') : null);

        return back()->with('success', $export->status === 'worker_not_configured' ? 'MDB export worker not configured. The intermediate payload is recorded; no MDB was generated.' : 'Export request recorded: '.$export->status.'.');
    }

    public function retryExport(Request $request, ExportJob $export)
    {
        $this->access->authorize($request->user(), $export->batch, 'export');
        app(ExportService::class)->retry($export, $request->user());

        return back()->with('success', 'Export retry requested.');
    }

    public function download(Request $request, ExportJob $export)
    {
        $this->access->authorize($request->user(), $export->batch, 'export');
        abort_unless($export->status === 'generated' && ! $export->superseded_at && $export->batch->approved_revision_id === $export->revision_id, 409, 'Only the current approved MDB can be downloaded.');
        $disk = Storage::disk($export->output_disk);
        abort_unless($export->output_path && $disk->exists($export->output_path), 404);
        abort_unless(hash_equals($export->output_sha256, hash_file('sha256', $disk->path($export->output_path))), 409, 'Output hash verification failed.');
        $this->audit->record($request->user(), 'mdb.output_downloaded', $export);
        if ($this->access->allows($request->user(), 'analyze')) {
            if (! $export->validations()->where('status', 'downloaded')->exists()) {
                ModelValidation::create(['export_job_id' => $export->id, 'recorded_by' => $request->user()->id, 'status' => 'downloaded']);
            }
        }

        return $disk->download($export->output_path, 'batch-'.$export->batch_id.'-revision-'.$export->revision->number.'.mdb', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function analyze(Request $request, ExportJob $export)
    {
        $this->access->authorize($request->user(), $export->batch, 'analyze');
        abort_unless($export->status === 'generated' && ! $export->superseded_at, 409);
        $data = $request->validate(['status' => 'required|in:downloaded,opened,connectivity_checked,load_flow_checked,accepted,returned',
            'comments' => 'required_if:status,returned|nullable|string|max:10000', 'synergee_version' => 'required_unless:status,downloaded|nullable|string|max:60', 'evidence_json' => 'nullable|json', 'evidence' => 'nullable|array']);
        DB::transaction(function () use ($request, $export, $data) {
            $batch = SurveyBatch::lockForUpdate()->findOrFail($export->batch_id);
            $locked = ExportJob::lockForUpdate()->findOrFail($export->id);
            abort_unless($locked->status === 'generated' && ! $locked->superseded_at && $batch->approved_revision_id === $locked->revision_id, 409);
            $events = $locked->validations()->orderBy('id')->get();
            $lastReturnedId = $events->where('status', 'returned')->last()?->id ?? 0;
            $completed = $events->where('id', '>', $lastReturnedId)->pluck('status')->all();
            $prerequisites = match ($data['status']) {
                'opened' => ['downloaded'], 'connectivity_checked' => ['opened'], 'load_flow_checked' => ['opened', 'connectivity_checked'],
                'accepted' => ['downloaded', 'opened', 'connectivity_checked', 'load_flow_checked'], default => [],
            };
            if (array_diff($prerequisites, $completed)) {
                throw ValidationException::withMessages(['status' => 'Record the earlier SynerGEE validation steps before this result.']);
            }
            $event = ModelValidation::create(array_intersect_key($data, array_flip(['status', 'comments', 'synergee_version'])) + ['export_job_id' => $locked->id,
                'recorded_by' => $request->user()->id, 'evidence' => empty($data['evidence_json']) ? ($data['evidence'] ?? null) : json_decode($data['evidence_json'], true)]);
            $this->audit->record($request->user(), 'mdb.model_validation', $event, [], $event->toArray(), $data['comments'] ?? null);
            if ($data['status'] === 'returned') {
                $this->snapshots->invalidate($batch);
                $batch->increment('revision');
                $batch->update(['status' => 'returned']);
            }
        });

        return back()->with('success', 'Analysis result recorded separately from MDB generation.');
    }

    public function configuration(Request $request, Project $project)
    {
        Gate::authorize('mdb-workflow.configure');

        return view('mdb-workflow.configuration', ['project' => $project, 'configuration' => Configuration::where('project_id', $project->id)->first(), 'templates' => Template::all()]);
    }

    public function saveConfiguration(Request $request, Project $project)
    {
        Gate::authorize('mdb-workflow.configure');
        $data = $request->validate(['epsg' => 'nullable|integer|between:1000,999999', 'settings_json' => 'required|json|max:262144', 'load_assumptions_json' => 'required|json|max:262144',
            'approved' => 'nullable|boolean', 'revision' => 'required|integer', 'reason' => 'required|string|min:5|max:4000',
            'entry_year_start' => 'nullable|integer|min:1800|max:9900', 'entry_height_unit' => 'nullable|in:ft,m']);
        DB::transaction(function () use ($request, $project, $data) {
            $config = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if (($config?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Configuration changed. Reload before saving.']);
            }
            $old = $config?->toArray() ?? [];
            $config ??= new Configuration(['project_id' => $project->id]);
            $settings = $this->jsonObject($data['settings_json'], 'settings_json');
            validator($settings, ['entry' => 'nullable|array'])->validate();
            if ($request->has('entry_year_start')) {
                $settings['entry']['two_digit_year_start'] = isset($data['entry_year_start']) ? (int) $data['entry_year_start'] : null;
            }
            if ($request->has('entry_height_unit')) {
                $settings['entry']['pole_height_unit'] = $data['entry_height_unit'] ?? null;
            }
            validator($settings, ['entry' => 'nullable|array', 'entry.two_digit_year_start' => 'nullable|integer|min:1800|max:9900', 'entry.pole_height_unit' => 'nullable|in:ft,m'])->validate();
            $config->fill(['epsg' => $data['epsg'] ?? null, 'settings' => $settings,
                'load_assumptions' => $this->jsonObject($data['load_assumptions_json'], 'load_assumptions_json'), 'revision' => ($config->revision ?? 0) + 1,
                'approved_by' => $request->boolean('approved') ? $request->user()->id : null, 'approved_at' => $request->boolean('approved') ? now('UTC') : null])->save();
            $this->audit->record($request->user(), 'mdb.configuration_changed', $config, $old, $config->toArray(), $data['reason']);
        });

        return back()->with('success', 'Project settings saved; affected batch approvals were invalidated.');
    }

    public function storeTemplate(Request $request)
    {
        Gate::authorize('mdb-workflow.configure');
        $data = $request->validate(['template_file' => 'required|file|max:102400', 'code' => 'required|string|max:100', 'version' => 'required|string|max:60',
            'synergee_version' => 'required|string|max:60', 'metadata_json' => 'required|json|max:262144', 'approved' => 'nullable|boolean']);
        $file = $request->file('template_file');
        $handle = fopen($file->getRealPath(), 'rb');
        $magic = fread($handle, 24);
        fclose($handle);
        if (strtolower($file->getClientOriginalExtension()) !== 'mdb' || ! str_contains($magic, 'Standard Jet DB')) {
            throw ValidationException::withMessages(['template_file' => 'An actual Microsoft Access Jet MDB template is required.']);
        }
        $metadata = $this->jsonObject($data['metadata_json'], 'metadata_json');
        if (Template::where('code', $data['code'])->where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'This template code/version already exists. Register a new version.']);
        }
        $path = 'mdb-workflow/templates/'.Str::uuid().'.mdb';
        $file->storeAs(dirname($path), basename($path), 'local');
        DB::transaction(function () use ($data, $file, $path, $metadata, $request) {
            $template = Template::create(array_intersect_key($data, array_flip(['code', 'version', 'synergee_version'])) + ['disk' => 'local', 'path' => $path,
                'sha256' => hash_file('sha256', $file->getRealPath()), 'metadata' => $metadata, 'active' => $request->boolean('approved'),
                'approved_by' => $request->boolean('approved') ? $request->user()->id : null, 'approved_at' => $request->boolean('approved') ? now('UTC') : null]);
            $this->audit->record($request->user(), 'mdb.template_registered', $template, [], $template->toArray());
        });

        return back()->with('success', 'Template registered. Worker will verify cleanliness, schema and hash before export.');
    }

    private function mutate(Request $request, SurveyBatch $batch, callable $operation, ?string $reason = null): void
    {
        $request->validate(['revision' => 'required|integer']);
        DB::transaction(function () use ($request, $batch, $operation, $reason) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertRevision($locked, (int) $request->input('revision'));
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $result = $operation($locked);
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.data_edited', $result ?? $locked, $old, $this->snapshots->snapshot($locked), $reason);
        });
    }

    private function assertRevision(SurveyBatch $batch, int $revision): void
    {
        if ($batch->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This batch changed. Reload before saving.']);
        }
    }

    private function sanitizeJson(array $data): array
    {
        // Bound user-supplied provenance/settings without interpreting handwriting.
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 256 * 1024) {
            throw ValidationException::withMessages(['data' => 'Structured entry exceeds 256 KB.']);
        }

        return $data;
    }

    private function jsonObject(string $value, string $field): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'Enter a JSON object.']);
        }

        return $this->sanitizeJson($decoded);
    }

    private function references(Request $request): array
    {
        $user = $request->user();
        $teams = SurveyTeam::where('status', 'active');
        if (! $user->hasAnyRole(['super_admin', 'project_manager'])) {
            $teams->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
        }
        $visibleProjects = $user->hasAnyRole(['super_admin', 'project_manager']) ? null : $this->access->visible($user)->select('project_id')->distinct();
        $projectIds = $teams->pluck('project_id')->merge($visibleProjects ? $visibleProjects->pluck('project_id') : []);

        return ['projects' => Project::where('status', 'active')->when($visibleProjects, fn ($q) => $q->whereIn('id', $projectIds))->orderBy('name')->get(),
            'feeders' => Feeder::active()->when($visibleProjects, fn ($q) => $q->whereIn('project_id', $projectIds))->orderBy('feeder_name')->get(), 'teams' => $teams->orderBy('name')->get()];
    }
}
