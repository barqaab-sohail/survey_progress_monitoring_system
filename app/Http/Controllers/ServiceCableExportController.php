<?php

namespace App\Http\Controllers;

use App\Models\Feeder;
use App\Models\User;
use App\Services\FieldSurveyAccess;
use App\Services\Mdb\WorkflowAccess;
use App\Services\ServiceCableExcelExport;
use App\Services\ServiceCableExportAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceCableExportController extends Controller
{
    private function feeders(User $user): Builder
    {
        abort_unless(app(ServiceCableExportAccess::class)->allows($user), 403);
        if ($user->hasAnyRole(['super_admin', 'project_manager', 'management_viewer'])) return Feeder::query();
        $fieldRoles = ['survey_team_leader', 'surveyor', 'mobile_survey_user'];
        $workflow = app(WorkflowAccess::class);
        abort_unless($user->hasAnyRole($fieldRoles) || $workflow->allows($user, 'view'), 403);
        return Feeder::where(function (Builder $query) use ($user, $fieldRoles, $workflow) {
            $query->whereRaw('1 = 0');
            if ($user->hasAnyRole($fieldRoles)) {
                $query->orWhereIn('id', app(FieldSurveyAccess::class)->feeders($user)->select('feeders.id'));
            }
            if ($workflow->allows($user, 'view')) {
                $query->orWhereIn('id', $workflow->visible($user)->select('feeder_id'));
            }
        });
    }

    public function index(Request $request, ServiceCableExcelExport $export)
    {
        $feeders = $this->feeders($request->user())->orderBy('feeder_code')->get();
        $data = $request->validate(['feeder_id' => 'nullable|integer']);
        $selected = isset($data['feeder_id']) ? $feeders->firstWhere('id', (int) $data['feeder_id']) : null;
        abort_if(isset($data['feeder_id']) && ! $selected, 404);
        $records = $selected ? $export->records($selected) : collect();
        return view('service-cable-exports.index', compact('feeders', 'selected', 'records'));
    }

    public function download(Request $request, Feeder $feeder, ServiceCableExcelExport $export)
    {
        abort_unless($this->feeders($request->user())->whereKey($feeder->id)->exists(), 404);
        $records = $export->records($feeder);
        $data = $request->validate([
            'transformers' => 'required|array|min:1',
            'transformers.*' => ['required', 'string', 'distinct', Rule::in($records->pluck('transformer_key')->unique()->all())],
        ], [
            'transformers.required' => 'Check at least one transformer to export.',
            'transformers.min' => 'Check at least one transformer to export.',
            'transformers.*.in' => 'A selected transformer is not available for this feeder. Reload the feeder records and select it again.',
        ]);
        $records = $records->whereIn('transformer_key', $data['transformers'])->values();
        $directory = storage_path('app/private/service-cable-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create service cable export directory.');
        }
        $path = tempnam($directory, 'cables-');
        if ($path === false) throw new \RuntimeException('Unable to create service cable export file.');
        $filename = $export->filename($feeder);
        try {
            $export->write($feeder, $path, $filename, $records);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }
}
