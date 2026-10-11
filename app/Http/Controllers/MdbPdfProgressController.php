<?php

namespace App\Http\Controllers;

use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdbPdfProgressController extends Controller
{
    public function __construct(private WorkflowAccess $access, private EntryService $entries, private AuditService $audit) {}

    public function layout(Request $request, SurveyBatch $batch, SourceFile $source)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($source->batch_id === $batch->id && $source->kind === 'pdf' && $source->status === 'ready', 404);
        $data = $request->validate(['revision' => 'required|integer', 'page' => 'required|integer|min:1',
            'row_count' => 'required|integer|min:1|max:200', 'bounds' => 'required|array:x,y,width,height',
            'bounds.x' => 'required|numeric|min:0|max:1', 'bounds.y' => 'required|numeric|min:0|max:1',
            'bounds.width' => 'required|numeric|gt:0|max:1', 'bounds.height' => 'required|numeric|gt:0|max:1']);
        $bounds = $data['bounds'];
        if ($data['page'] > ($source->metadata['page_count'] ?? 0) || $bounds['x'] + $bounds['width'] > 1.000001 || $bounds['y'] + $bounds['height'] > 1.000001) {
            throw ValidationException::withMessages(['bounds' => 'Select a row area within a valid PDF page.']);
        }
        DB::transaction(function () use ($request, $batch, $source, $data, $bounds) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->access->authorize($request->user(), $locked, 'edit');
            abort_unless($locked->revision === (int) $data['revision'], 409, 'Saved records changed. Refresh before updating row progress.');
            $rows = PdfRow::where('source_file_id', $source->id)->where('page', $data['page']);
            $existing = $rows->orderBy('row_number')->get();
            if ($existing->contains(fn ($row) => $row->entry_row_id) && $existing->count() !== (int) $data['row_count']) {
                throw ValidationException::withMessages(['rows' => 'This page has linked records. Keep its row count to preserve their locations. You can redraw the area to adjust alignment.']);
            }
            $old = $existing->toArray();
            $keepRows = $existing->count() === (int) $data['row_count'];
            if (! $keepRows) {
                $rows->delete();
            }
            for ($index = 0; $index < $data['row_count']; $index++) {
                $rowBounds = ['x' => (float) $bounds['x'], 'y' => $bounds['y'] + $index * $bounds['height'] / $data['row_count'],
                    'width' => (float) $bounds['width'], 'height' => $bounds['height'] / $data['row_count']];
                if ($keepRows) {
                    $existing[$index]->update(['bounds' => $rowBounds]);
                } else {
                    PdfRow::create(['source_file_id' => $source->id, 'page' => $data['page'], 'row_number' => $index + 1, 'bounds' => $rowBounds]);
                }
            }
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.pdf_row_layout_saved', $source, $old, $data);
        });

        return response()->json($this->entries->editorData($batch->fresh()));
    }

    public function mark(Request $request, SurveyBatch $batch, PdfRow $pdfRow)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($batch->sources()->where('kind', 'pdf')->whereKey($pdfRow->source_file_id)->exists(), 404);
        $data = $request->validate(['revision' => 'required|integer', 'entered' => 'required|boolean']);
        DB::transaction(function () use ($request, $batch, $pdfRow, $data) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->access->authorize($request->user(), $locked, 'edit');
            abort_unless($locked->revision === (int) $data['revision'], 409, 'Saved records changed. Refresh before updating row progress.');
            $marker = $pdfRow->fresh();
            if ($data['entered'] && ! $marker->entry_row_id) {
                throw ValidationException::withMessages(['row' => 'Save the corresponding record before marking this row Entered.']);
            }
            $old = $marker->toArray();
            $marker->update(['entered' => $data['entered']]);
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.pdf_row_progress_updated', $marker, $old, $marker->fresh()->toArray());
        });

        return response()->json($this->entries->editorData($batch->fresh()));
    }
}
