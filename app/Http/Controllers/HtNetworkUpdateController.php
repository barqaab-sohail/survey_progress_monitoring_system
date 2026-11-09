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
    public function index(Request $request, HtNetworkWorkflow $workflow)
    {
        $updates = $workflow->visible($request->user())->with(['feeder', 'collector'])->latest()->paginate(40);

        return view('ht-updates.index', compact('updates'));
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
        abort_if($update->collected_by === $request->user()->id, 403, 'The collector cannot verify their own HT update.');
        $data = $request->validate(['revision' => 'required|integer', 'decision' => 'required|in:verified,returned',
            'review_note' => 'required|string|max:4000', 'existing_transformer_id' => 'nullable|integer',
            'connection_confirmed' => 'exclude_unless:decision,verified|required|accepted',
            'nodes' => 'required_if:decision,verified|array', 'nodes.*.latitude' => 'required_if:decision,verified|nullable|numeric|between:-90,90',
            'nodes.*.longitude' => 'required_if:decision,verified|nullable|numeric|between:-180,180']);
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
