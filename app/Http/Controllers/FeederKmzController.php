<?php

namespace App\Http\Controllers;

use App\Models\Feeder;
use App\Models\FeederKmzExport;
use App\Services\FeederKmzService;
use App\Services\FeederKmzHistoryService;
use App\Services\FeederKmzPreviewService;
use Illuminate\Http\Request;

class FeederKmzController extends Controller
{
    public function index(Request $request, FeederKmzService $service)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        $data = $request->validate(['feeder_id' => 'nullable|integer|exists:feeders,id']);
        $feeders = Feeder::orderBy('feeder_code')->get();
        $feeder = isset($data['feeder_id']) ? $feeders->firstWhere('id', (int) $data['feeder_id']) : null;
        $prepared = $feeder ? $service->prepare($feeder) : null;
        $history = $feeder ? FeederKmzExport::where('feeder_id', $feeder->id)->with('creator')->latest('id')->paginate(10)->withQueryString() : null;

        return view('feeder-kmz.index', compact('feeders', 'feeder', 'prepared', 'history'));
    }

    public function export(Request $request, Feeder $feeder, FeederKmzHistoryService $history)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        $record = $history->create($feeder, $request->user());
        return response()->download($history->path($record), $record->filename,
            ['Content-Type' => 'application/vnd.google-earth.kmz', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, Feeder $feeder, FeederKmzPreviewService $preview)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        return response()->json($preview->current($feeder))->header('Cache-Control', 'private, no-store');
    }

    public function showHistory(Request $request, FeederKmzExport $export)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        $export->load(['feeder', 'creator']);
        return view('feeder-kmz.history', compact('export'));
    }

    public function downloadHistory(Request $request, FeederKmzExport $export, FeederKmzHistoryService $history)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        return response()->download($history->path($export), $export->filename,
            ['Content-Type' => 'application/vnd.google-earth.kmz', 'Cache-Control' => 'private, no-store']);
    }

    public function previewHistory(Request $request, FeederKmzExport $export, FeederKmzHistoryService $history, FeederKmzPreviewService $preview)
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'project_manager']), 403);
        return response()->json($preview->fromFile($history->path($export)) + ['counts' => $export->counts, 'warnings' => $export->warnings])
            ->header('Cache-Control', 'private, no-store');
    }
}
