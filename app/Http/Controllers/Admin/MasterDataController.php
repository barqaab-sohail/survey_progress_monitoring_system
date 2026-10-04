<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Project;
use App\Models\SubDivision;
use App\Services\AuditService;
use App\Services\FeederImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function index(Request $request): View
    {
        $feeders = Feeder::with(['project', 'circle', 'division', 'subDivision', 'gridStation'])
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($nested) => $nested->where('feeder_code', 'like', '%'.$request->q.'%')->orWhere('feeder_name', 'like', '%'.$request->q.'%')))
            ->orderBy('feeder_code')->paginate(30)->withQueryString();

        return view('admin.master.index', [
            'feeders' => $feeders,
            'counts' => ['projects' => Project::count(), 'circles' => Circle::count(), 'divisions' => Division::count(), 'sub_divisions' => SubDivision::count(), 'grids' => GridStation::count(), 'feeders' => Feeder::count()],
        ]);
    }

    public function edit(Feeder $feeder): View
    {
        return view('admin.master.edit', compact('feeder'));
    }

    public function update(Request $request, Feeder $feeder, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $feeder);

        $data = $request->validate([
            'feeder_name' => ['required', 'string', 'max:255'], 'total_transformers' => ['required', 'integer', 'min:1'],
            'survey_drive_url' => ['nullable', 'url:http,https'], 'mdb_drive_url' => ['nullable', 'url:http,https'],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $data['baseline_pending'] = false;
        $data['demo_baseline'] = false;
        $old = $feeder->toArray();
        $feeder->update($data);
        $audit->record($request->user(), 'feeder.updated', $feeder, $old, $feeder->fresh()->toArray(), $request->input('reason'));

        return redirect()->route('admin.master.index')->with('success', 'Feeder master data updated.');
    }

    public function import(Request $request, FeederImportService $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $result = $importer->import($request->file('file'), $request->user());

        return redirect()->route('admin.master.index')->with('success', "Import complete: {$result['imported']} imported, {$result['updated']} updated, {$result['rejected']} rejected.")->with('import_result', $result);
    }

    public function template()
    {
        $headers = ['project_code', 'project_name', 'circle_code', 'circle_name', 'division_code', 'division_name', 'sub_division_code', 'sub_division_name', 'grid_station_code', 'grid_station_name', 'feeder_code', 'feeder_name', 'total_transformers', 'survey_drive_url', 'mdb_drive_url', 'processing_drive_url', 'processing_required', 'status'];

        return response()->streamDownload(function () use ($headers) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $headers);
            fputcsv($out, ['HAZECO-TDL', 'HAZECO T&D Losses Project', 'HC-N', 'North Circle', 'HC-N-D1', 'North Division 1', 'HC-N-D1-SD1', 'North Sub-Division', 'GS-01', 'Grid Station 1', 'F-01', 'Feeder 01', 145, 'https://drive.google.com/example', '', '', 'yes', 'active']);
            fclose($out);
        }, 'hazeco-feeder-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
