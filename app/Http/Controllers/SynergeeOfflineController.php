<?php

namespace App\Http\Controllers;

use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\SynergeeTransfer;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SynergeeOfflineController extends Controller
{
    public function export(Request $request, SurveyBatch $batch, WorkflowAccess $access, SynergeeTransfer $transfer)
    {
        $access->authorize($request->user(), $batch, 'export');
        if ($batch->staged_workflow) {
            $access->authorize($request->user(), $batch, 'generate');
        }
        $data = $request->validate(['transformer_id' => 'nullable|integer|min:1', 'revision_sha256' => 'required|regex:/^[a-f0-9]{64}$/D']);
        $package = DB::transaction(function () use ($request, $batch, $access, $transfer, $data) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $access->authorize($request->user(), $locked, 'export');
            if ($locked->staged_workflow) {
                $access->authorize($request->user(), $locked, 'generate');
            }
            abort_unless($locked->approvedRevision && hash_equals($locked->approvedRevision->sha256, $data['revision_sha256']), 409, 'Survey approval changed. Reload and export its current verified revision.');
            $package = $transfer->export($locked, isset($data['transformer_id']) ? (int) $data['transformer_id'] : null);
            app(AuditService::class)->record($request->user(), 'mdb.synergee_json_exported', $locked, [], [
                'revision_sha256' => $package['source']['revision_sha256'], 'scope' => $package['scope'], 'package_sha256' => $package['sha256']]);

            return $package;
        });
        $json = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > SynergeeTransfer::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'SynerGEE JSON exceeds 30 MB. Export a smaller survey batch or template.']);
        }
        $code = count($package['scope']['transformer_codes']) === 1 ? Str::slug($package['scope']['transformer_codes'][0]) : 'feeder';

        return response()->streamDownload(fn () => print ($json), 'synergee_'.$code.'_batch-'.$batch->id.'_r'.$package['source']['revision_number'].'.json',
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    public function index()
    {
        $this->authorizeLocal();

        return view('mdb-workflow.offline-synergee');
    }

    public function preview(Request $request, SynergeeTransfer $transfer)
    {
        $this->authorizeLocal();
        try {
            $request->validate(['file' => 'required|file|extensions:json|max:30720', 'gpx' => 'nullable|file|extensions:gpx|max:10240']);
            $json = $request->file('file')->get();
            $package = $transfer->read($json);
            $inspection = $transfer->inspect($package, $request->file('gpx')?->get());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('mdb-workflow.offline-synergee.index'));
        }

        return view('mdb-workflow.offline-synergee', $inspection + ['packageJson' => $json, 'gpxChecked' => $request->hasFile('gpx')]);
    }

    public function download(Request $request, SynergeeTransfer $transfer)
    {
        $this->authorizeLocal();
        try {
            $request->validate(['package' => 'required|string|max:31457280']);
            $package = $transfer->read($request->string('package')->toString());
            $path = $transfer->generate($package);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('mdb-workflow.offline-synergee.index'));
        }
        $code = count($package['scope']['transformer_codes']) === 1 ? Str::slug($package['scope']['transformer_codes'][0]) : 'feeder';

        return response()->download($path, 'synergee_'.$code.'_batch-'.$package['source']['batch_id'].'_r'.$package['source']['revision_number'].'.mdb',
            ['Content-Type' => 'application/x-msaccess', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    private function authorizeLocal(): void
    {
        Gate::authorize('mdb-workflow.export');
        abort_unless(PHP_OS_FAMILY === 'Windows' && config('mdb_workflow.offline_enabled'), 404);
    }
}
