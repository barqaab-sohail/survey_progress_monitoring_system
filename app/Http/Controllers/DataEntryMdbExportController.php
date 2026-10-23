<?php

namespace App\Http\Controllers;

use App\Models\Mdb\SurveyBatch;
use App\Services\Mdb\DataEntryMdbExport;
use App\Services\Mdb\TransformerTransfer;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DataEntryMdbExportController extends Controller
{
    public function __construct(private WorkflowAccess $access, private DataEntryMdbExport $exporter) {}

    public function show(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch, 'export');

        return view('mdb-workflow.data-entry-export', ['batch' => $batch, 'payload' => $this->exporter->prepare($batch)]);
    }

    public function transformerJson(Request $request, SurveyBatch $batch, int $transformer, TransformerTransfer $transfer)
    {
        $this->access->authorize($request->user(), $batch, 'export');
        $request->validate(['revision_sha256' => 'required|string|size:64']);
        $package = $transfer->export($batch, $transformer);
        abort_unless(hash_equals($package['source']['revision_sha256'], $request->string('revision_sha256')->toString()), 409, 'Approval changed. Review the current export again.');
        $code = Str::slug($package['snapshot']['transformers'][0]['code']) ?: 'transformer';
        $filename = 'transformer_'.$code.'_batch-'.$batch->id.'_r'.$package['source']['revision_number'].'.json';
        $json = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 10485760) {
            throw ValidationException::withMessages(['transformer_id' => 'This transformer exceeds the 10 MB offline transfer limit.']);
        }

        return response()->streamDownload(fn () => print ($json), $filename, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch, 'export');
        $request->validate(['revision_sha256' => 'required|string|size:64']);
        $payload = $this->exporter->prepare($batch);
        abort_unless(hash_equals($payload['revision_sha256'], $request->string('revision_sha256')->toString()), 409, 'Approval changed. Review the current export again.');
        $path = $this->exporter->generate($payload);
        // Recheck after the external worker in case a survey was reopened during generation.
        try {
            $fresh = $this->exporter->prepare($batch->fresh());
            abort_unless(hash_equals($payload['revision_sha256'], $fresh['revision_sha256']), 409, 'Approval changed during export.');
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
        $project = Str::slug($batch->project?->code ?? 'survey');
        $filename = 'data_entry_'.$project.'_batch-'.$batch->id.'_r'.$payload['revision_number'].'.mdb';

        return response()->download($path, $filename, ['Content-Type' => 'application/x-msaccess', 'Cache-Control' => 'private, no-store',
            'X-Data-Entry-Observations' => (string) count($payload['rows']), 'X-Data-Entry-Revision' => $payload['revision_sha256']])->deleteFileAfterSend(true);
    }
}
