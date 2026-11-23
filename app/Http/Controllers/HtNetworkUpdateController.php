<?php

namespace App\Http\Controllers;

use App\Models\Feeder;
use App\Models\HtNetworkUpdate;
use App\Services\AuditService;
use App\Services\HtKmzService;
use App\Services\HtNetworkWorkflow;
use App\Services\Mdb\GpxParser;
use Illuminate\Http\Request;

class HtNetworkUpdateController extends Controller
{
    private function formData(Request $request, ?HtNetworkUpdate $update = null): array
    {
        $workflow = app(HtNetworkWorkflow::class);
        abort_unless($workflow->canCreateWeb($request->user()), 403);
        if ($update) {
            $this->visible($request, $update);
            abort_unless(($update->collected_by === $request->user()->id || $request->user()->hasRole('super_admin')) && $update->status !== 'verified', 403);
        }
        $access = app(\App\Services\FieldSurveyAccess::class);
        $manager = $request->user()->hasAnyRole(['super_admin', 'project_manager']);
        $feeders = ($manager ? Feeder::active()->whereHas('project', fn ($q) => $q->where('status', 'active')) : $access->feeders($request->user()))->orderBy('feeder_code')->get();
        $kmz = app(HtKmzService::class);
        // Match baseline(): only the newest completed import may supply the HT reference.
        $imports = \App\Models\TransformerKmzImport::whereIn('feeder_id', $feeders->modelKeys())
            ->where('status', 'completed')->latest('id')->get()->unique('feeder_id')->keyBy('feeder_id');
        $feeders = $feeders->filter(function ($feeder) use ($imports, $kmz) {
            $import = $imports->get($feeder->id);
            if (! $import || ! $import->stored_path) return false;
            try {
                $kmz->path($import);
                return true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 422) throw $error;
                return false;
            }
        })->values();
        $teams = ($manager ? \App\Models\SurveyTeam::where('status', 'active') : $access->teams($request->user()))->get();
        $feederId = $update?->feeder_id ?? $request->integer('feeder_id');
        $selected = $feeders->firstWhere('id', $feederId);
        $selectionMessage = $feederId && ! $selected
            ? 'The selected feeder has no available imported KMZ. Import or restore its original KMZ, then select it again.' : null;
        $network = ['baseline_sha256' => null, 'features' => []];
        if ($selected) {
            try {
                $network = $kmz->network($selected->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 422) throw $error;
                $selectionMessage = 'The selected feeder KMZ could not be loaded. Import or restore a valid original KMZ before continuing.';
            }
        }
        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
            if ($selectionMessage) throw \Illuminate\Validation\ValidationException::withMessages(['feeder_id' => $selectionMessage]);
        }
        return compact('update', 'feeders', 'teams', 'selected', 'network', 'selectionMessage');
    }

    public function create(Request $request)
    {
        return view('ht-updates.form', $this->formData($request));
    }

    public function edit(Request $request, HtNetworkUpdate $update)
    {
        return view('ht-updates.form', $this->formData($request, $update));
    }

    public function store(Request $request, ?HtNetworkUpdate $update = null)
    {
        $form = $this->formData($request, $update);
        $data = $request->validate([
            'client_uuid' => 'required|uuid', 'base_revision' => 'required|integer|min:0',
            'feeder_id' => 'required|integer', 'survey_team_id' => 'required|integer',
            'transformer_code' => 'required|string|max:100', 'kind' => 'required|in:missing_transformer,ht_extension',
            'upstream_feature_id' => 'required|string|max:200', 'baseline_sha256' => 'required|string|size:64',
            'capacity_kva' => ['nullable', 'numeric', \Illuminate\Validation\Rule::in(\App\Services\Mdb\ApprovedWarehouse::capacities())],
            'make' => 'nullable|string|max:200', 'location' => 'nullable|string|max:500',
            'mounting' => 'nullable|in:S.Pole,D.Pole,Pad', 'phase' => 'nullable|in:R,Y,B,R-Y,Y-B,R-B,R-Y-B',
            'observed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'remarks' => 'nullable|string|max:4000',
            'nodes' => 'required|array|min:2|max:200',
            'nodes.*' => 'array:waypoint,latitude,longitude,pole_type,pole_height,conductor_r,conductor_y,conductor_b',
            'nodes.*.waypoint' => 'required|string|max:100'.($request->input('kind') === 'ht_extension' ? '|distinct' : ''),
            'nodes.*.latitude' => 'required|numeric|between:-90,90',
            'nodes.*.longitude' => 'required|numeric|between:-180,180',
            'nodes.*.pole_type' => 'nullable|string|max:100', 'nodes.*.pole_height' => 'nullable|numeric|min:0|max:1000',
            'nodes.*.conductor_r' => 'nullable|string|max:100', 'nodes.*.conductor_y' => 'nullable|string|max:100', 'nodes.*.conductor_b' => 'nullable|string|max:100',
            'photos' => 'nullable|array|max:3', 'photos.*' => 'file|mimes:jpg,jpeg,png|max:1024',
        ], [
            'nodes.*.latitude.required' => 'Enter latitude for route row #:position before submitting the HT update.',
            'nodes.*.longitude.required' => 'Enter longitude for route row #:position before submitting the HT update.',
        ], [
            'nodes.*.latitude' => 'route latitude',
            'nodes.*.longitude' => 'route longitude',
        ]);
        abort_if($update && ($update->client_uuid !== $data['client_uuid'] || $update->feeder_id !== (int) $data['feeder_id']), 409, 'Keep the original HT update identity and feeder.');
        abort_unless($form['feeders']->contains('id', (int) $data['feeder_id']), 403);
        $data += array_fill_keys(['capacity_kva', 'make', 'location', 'mounting', 'phase', 'observed_on', 'remarks'], null);
        $data['upstream_reference'] = collect($form['network']['features'])->firstWhere('id', $data['upstream_feature_id'])['label'] ?? $data['upstream_feature_id'];
        $data['photos'] = $update?->payload['photos'] ?? [];
        foreach ($request->file('photos', []) as $photo) $data['photos'][] = base64_encode($photo->get());
        if (count($data['photos']) > 3) throw \Illuminate\Validation\ValidationException::withMessages(['photos' => 'A maximum of three photographs is allowed, including existing photographs.']);
        try {
            $saved = app(HtNetworkWorkflow::class)->syncWeb($request->user(), $data);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) throw $error;
            $request->session()->flashInput($request->except(['photos', '_token']));
            $errors = new \Illuminate\Support\ViewErrorBag;
            $errors->put('default', new \Illuminate\Support\MessageBag([
                'base_revision' => 'This HT update changed or is already verified. Your entered values are shown below. Reload the latest HT update before applying corrections.',
            ]));
            return response()->view('ht-updates.form', array_merge($form, compact('errors')), 409);
        }
        return redirect()->route('ht-updates.show', $saved)->with('success', 'HT network update submitted for HT verification. LT entry becomes available after HT approval.');
    }

    public function index(Request $request, HtNetworkWorkflow $workflow)
    {
        $updates = $workflow->visible($request->user())->with(['feeder', 'collector'])->latest()->paginate(40);

        return view('ht-updates.index', compact('updates'));
    }

    public function destroy(Request $request, HtNetworkUpdate $update)
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        $this->visible($request, $update);
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $update, $data) {
            \App\Models\Feeder::whereKey($update->feeder_id)->lockForUpdate()->firstOrFail();
            $locked = HtNetworkUpdate::whereKey($update->id)->lockForUpdate()->firstOrFail();
            if ($locked->revision !== (int) $data['revision']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ht_update' => 'This HT update changed. Refresh the list before deleting it.']);
            }
            if ($locked->status === 'verified' || $locked->transformer_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ht_update' => 'Approved HT updates cannot be deleted because they form part of the feeder network.']);
            }
            $linked = \App\Models\FieldSurvey::withTrashed()->where('feeder_id', $locked->feeder_id)->get(['ht_network_update_id', 'transformer_code'])
                ->contains(fn ($survey) => $survey->ht_network_update_id === $locked->id
                    || \App\Support\FieldTransformerIdentity::key($survey->transformer_code) === $locked->transformer_key);
            if ($linked) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ht_update' => 'This HT update has linked LT survey work and cannot be deleted.']);
            }
            app(AuditService::class)->record($request->user(), 'ht_update.deleted', $locked, $locked->summary());
            $locked->delete();
        });

        return redirect()->route('ht-updates.index')->with('success', 'HT network update deleted.');
    }

    private function visible(Request $request, HtNetworkUpdate $update): void
    {
        abort_unless(app(HtNetworkWorkflow::class)->visible($request->user())->whereKey($update->id)->exists(), 404);
    }

    public function show(Request $request, HtNetworkUpdate $update)
    {
        $this->visible($request, $update);
        $network = app(HtKmzService::class)->network($update->feeder_id);

        return view('ht-updates.show', compact('update', 'network'));
    }

    public function review(Request $request, HtNetworkUpdate $update, HtNetworkWorkflow $workflow)
    {
        $this->visible($request, $update);
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        abort_if($update->collected_by === $request->user()->id && ! $request->user()->hasRole('super_admin'), 403, 'Only a Super Admin can verify their own HT update.');
        $data = $request->validate(['revision' => 'required|integer', 'decision' => 'required|in:verified,returned',
            'review_note' => 'required|string|max:4000', 'existing_transformer_id' => 'nullable|integer',
            'connection_confirmed' => 'exclude_unless:decision,verified|required|accepted',
            'nodes' => 'required_if:decision,verified|array', 'nodes.*.latitude' => 'required_if:decision,verified|nullable|numeric|between:-90,90',
            'nodes.*.longitude' => 'required_if:decision,verified|nullable|numeric|between:-180,180'], [
                'nodes.*.latitude.required_if' => 'Latitude is missing for route row #:position. Edit the HT update to complete its coordinates.',
                'nodes.*.longitude.required_if' => 'Longitude is missing for route row #:position. Edit the HT update to complete its coordinates.',
            ]);
        $workflow->review($request->user(), $update, $data);

        return back()->with('success', 'HT Network Update '.$data['decision'].'. Linked LT observations are retained.');
    }

    public function resolveGpx(Request $request, HtNetworkUpdate $update)
    {
        $this->visible($request, $update);
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        $request->validate(['gpx' => 'required|file|max:10240', 'revision' => 'required|integer']);
        abort_unless($update->revision === $request->integer('revision') && $update->status === 'pending', 409, 'The HT update changed. Reload the review.');
        $points = app(GpxParser::class)->parse($request->file('gpx')->get())['waypoints'];
        $nodes = $update->payload['nodes'];
        foreach ($nodes as &$node) {
            $matches = array_values(array_filter($points, fn ($p) => $p['name'] === $node['waypoint']));
            if (count($matches) === 1) {
                $node['latitude'] = $matches[0]['latitude'];
                $node['longitude'] = $matches[0]['longitude'];
            }
        }
        unset($node);

        // Preview only: approval explicitly commits these coordinates with an audit note.
        return redirect()->route('ht-updates.show', $update)->withInput(['nodes' => $nodes, 'revision' => $request->integer('revision')])->with('success', 'Exact GPX waypoint matches filled for review. Check every coordinate and confirm the actual upstream connection before verification.');
    }

    public function photo(Request $request, HtNetworkUpdate $update, int $index)
    {
        $this->visible($request, $update);
        $photo = $update->payload['photos'][$index] ?? null;
        abort_unless($photo, 404);
        $bytes = base64_decode($photo, true);
        $mime = @getimagesizefromstring($bytes)['mime'] ?? 'application/octet-stream';

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, Feeder $feeder, HtKmzService $kmz)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        $path = $kmz->export($feeder);
        app(AuditService::class)->record($request->user(), 'ht_network.kmz_exported', $feeder, [], ['sha256' => hash_file('sha256', $path)]);

        return response()->download($path, 'HT_'.$feeder->feeder_code.'_verified_'.now()->format('Ymd_His').'.kmz', ['Content-Type' => 'application/vnd.google-earth.kmz', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
