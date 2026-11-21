<?php

namespace App\Http\Controllers;

use App\Models\Mdb\EntryCorrection;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MdbEntryCorrectionController extends Controller
{
    public function __construct(private WorkflowAccess $access, private AuditService $audit) {}

    public function index(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch);

        return response()->json($this->data($batch));
    }

    public function store(Request $request, SurveyBatch $batch)
    {
        // Upload access lets an operator report a damaged PDF even while source processing is incomplete.
        $this->access->authorize($request->user(), $batch, 'upload');
        $data = $request->validate([
            'client_uuid' => 'required|uuid', 'transformer_id' => 'nullable|integer',
            'transformer_reference' => 'required|string|max:255',
            'source_file_id' => 'required|integer', 'source_page' => 'required|integer|min:1|max:10000',
            'source_row' => 'nullable|string|max:100', 'comment' => 'required|string|min:5|max:4000',
            'screenshot' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|max:5120',
        ]);
        $screenshot = $request->file('screenshot');
        if ($screenshot && @getimagesize($screenshot->getRealPath()) === false) {
            throw ValidationException::withMessages(['screenshot' => 'Choose a valid PNG, JPG or WebP screenshot.']);
        }
        unset($data['screenshot']);
        $screenshotHash = $screenshot ? hash_file('sha256', $screenshot->getRealPath()) : null;
        $storedPath = null;
        try {
            DB::transaction(function () use ($request, $batch, $data, $screenshot, $screenshotHash, &$storedPath) {
                $locked = SurveyBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($request->user(), $locked, 'upload');
                abort_unless($locked->staged_workflow ? in_array($locked->survey_status, ['entry', 'returned'], true)
                    : in_array($locked->status, ['draft', 'returned'], true), 403, 'Entry is locked.');
                $pdf = $locked->sources()->where('kind', 'pdf')->findOrFail($data['source_file_id']);
                if (isset($pdf->metadata['page_count']) && $data['source_page'] > $pdf->metadata['page_count']) {
                    throw ValidationException::withMessages(['source_page' => 'Choose a page within the selected PDF.']);
                }
                if (! empty($data['transformer_id'])) {
                    $transformer = $locked->transformers()->findOrFail($data['transformer_id']);
                    $data['transformer_reference'] = $transformer->code;
                }
                $existing = $locked->entryCorrections()->where('client_uuid', $data['client_uuid'])->first();
                if ($existing) {
                    abort_unless($existing->screenshot_sha256 === $screenshotHash, 409, 'This retry has a different screenshot. Refresh corrections before retrying.');
                    foreach (['transformer_id', 'transformer_reference', 'source_file_id', 'source_page', 'source_row', 'comment'] as $field) {
                        if ((string) $existing->$field !== (string) ($data[$field] ?? null) || $existing->reported_by !== $request->user()->id) {
                            abort(409, 'This retry belongs to a different comment. Refresh corrections before retrying.');
                        }
                    }

                    return;
                }
                if ($screenshot) {
                    $storedPath = $screenshot->store('mdb-entry-corrections/'.$locked->id, 'local');
                    if (! $storedPath) {
                        throw new \RuntimeException('The screenshot could not be stored. Retry with the retained comment.');
                    }
                }
                $note = $locked->entryCorrections()->create($data + [
                    'pdf_name' => $pdf->original_name, 'pdf_version' => $pdf->version ?? 1,
                    'reported_by' => $request->user()->id, 'reporter_name' => $request->user()->name, 'status' => 'open',
                    'screenshot_path' => $storedPath, 'screenshot_mime' => $screenshot?->getMimeType(), 'screenshot_sha256' => $screenshotHash,
                ]);
                $this->audit->record($request->user(), 'mdb.entry_correction_reported', $note, [], $note->toArray());
            });
        } catch (\Throwable $exception) {
            if ($storedPath) Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        return response()->json($this->data($batch));
    }

    public function screenshot(Request $request, SurveyBatch $batch, EntryCorrection $correction)
    {
        $this->access->authorize($request->user(), $batch);
        abort_unless($correction->batch_id === $batch->id && $correction->screenshot_path, 404);
        abort_unless(in_array($correction->screenshot_mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($correction->screenshot_path), 404);

        return $disk->response($correction->screenshot_path, 'correction-screenshot', [
            'Content-Type' => $correction->screenshot_mime, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox',
        ], 'inline');
    }

    public function resolve(Request $request, SurveyBatch $batch, EntryCorrection $correction)
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        $this->access->authorize($request->user(), $batch);
        abort_unless($correction->batch_id === $batch->id, 404);
        $data = $request->validate(['resolution' => 'required|string|min:5|max:4000']);
        DB::transaction(function () use ($request, $batch, $correction, $data) {
            $locked = SurveyBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $locked);
            $note = $locked->entryCorrections()->lockForUpdate()->findOrFail($correction->id);
            abort_unless($note->status === 'open', 409, 'This correction has already been resolved. Refresh the list.');
            $before = $note->toArray();
            $note->update($data + ['status' => 'resolved', 'resolved_by' => $request->user()->id,
                'resolver_name' => $request->user()->name, 'resolved_at' => now('UTC')]);
            $this->audit->record($request->user(), 'mdb.entry_correction_resolved', $note, $before, $note->toArray());
        });

        if (! $request->expectsJson()) {
            return back()->with('success', 'PDF correction resolved. The operator can resume entry.');
        }

        return response()->json($this->data($batch));
    }

    private function data(SurveyBatch $batch): array
    {
        return ['corrections' => $batch->entryCorrections()->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('id')->get()->toArray()];
    }
}
