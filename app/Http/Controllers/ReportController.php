<?php

namespace App\Http\Controllers;

use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\SurveyTeam;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): View
    {
        [$type, $filters] = $this->input($request);

        return view('reports.index', $this->viewData($type, $filters, $reports->generate($type, $filters)));
    }

    public function csv(Request $request, ReportService $reports)
    {
        [$type, $filters] = $this->input($request);
        $report = $reports->generate($type, $filters);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $report['headers']);
            foreach ($report['rows'] as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, str($report['title'])->slug().'-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function input(Request $request): array
    {
        $data = $request->validate([
            'report' => ['nullable', Rule::in(array_keys(ReportService::TYPES))],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'circle_id' => ['nullable', 'exists:circles,id'], 'division_id' => ['nullable', 'exists:divisions,id'],
            'grid_station_id' => ['nullable', 'exists:grid_stations,id'], 'feeder_id' => ['nullable', 'exists:feeders,id'],
            'survey_team_id' => ['nullable', 'exists:survey_teams,id'], 'organization_id' => ['nullable', 'exists:organizations,id'],
            'progress_status' => ['nullable', Rule::in(['BASELINE PENDING', 'NOT STARTED', 'SURVEY RUNNING', 'VERIFICATION PENDING', 'MDB CREATION RUNNING', 'MDB VERIFICATION PENDING', 'MDB RETURNED', 'COMPLETED'])],
        ]);
        $type = $data['report'] ?? 'overall';
        $data['from'] = $data['from'] ?? today()->startOfMonth()->toDateString();
        $data['to'] = $data['to'] ?? today()->toDateString();
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to' => 'A single report date range cannot exceed 366 days.']);
        }

        return [$type, $data];
    }

    private function viewData(string $type, array $filters, array $report): array
    {
        return compact('type', 'filters', 'report') + [
            'reportTypes' => ReportService::TYPES,
            'circles' => Circle::orderBy('name')->get(), 'divisions' => Division::orderBy('name')->get(),
            'grids' => GridStation::orderBy('name')->get(), 'feeders' => Feeder::orderBy('feeder_code')->get(),
            'surveyTeams' => SurveyTeam::orderBy('name')->get(), 'organizations' => Organization::where('type', 'third_party')->orderBy('name')->get(),
        ];
    }
}
