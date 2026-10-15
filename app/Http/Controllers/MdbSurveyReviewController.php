<?php

namespace App\Http\Controllers;

use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\ReviewService;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdbSurveyReviewController extends Controller
{
    public function state(Request $request, SurveyBatch $batch, NetworkTransformer $transformer)
    {
        app(WorkflowAccess::class)->authorize($request->user(), $batch);
        abort_unless($transformer->batch_id === $batch->id, 404);

        return response()->json(app(ReviewService::class)->state($batch, $transformer, $request->user()->id));
    }

    public function check(Request $request, SurveyBatch $batch, NetworkTransformer $transformer)
    {
        app(WorkflowAccess::class)->authorizeReviewChecks($request->user(), $batch);
        abort_unless($transformer->batch_id === $batch->id, 404);
        $data = $request->validate(['revision'=>'required|integer','fields'=>'required|array|min:1|max:500','fields.*.key'=>'required|string|max:120',
            'fields.*.hash'=>'required|string|size:64','checked'=>'required|boolean']);
        return DB::transaction(function () use ($request, $batch, $transformer, $data) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            app(WorkflowAccess::class)->authorizeReviewChecks($request->user(), $locked);
            if ($locked->revision !== (int) $data['revision']) throw ValidationException::withMessages(['revision'=>'Saved data changed. Refresh the review before checking values.']);
            $state = app(ReviewService::class)->state($locked, $transformer, $request->user()->id);
            $fields = collect($state['header']['fields'])->concat(collect($state['pairs'])->flatMap(fn ($pair)=>collect($pair['rows'])->flatMap(fn ($row)=>$row['fields'])))->keyBy('key');
            $checked = array_fill_keys($state['checked'], true);
            foreach ($data['fields'] as $submitted) {
                $field = $fields[$submitted['key']] ?? null;
                if (! $field || ! hash_equals($field['hash'], $submitted['hash'])) throw ValidationException::withMessages(['fields'=>'This value changed or does not belong to this transformer. Refresh and check it again.']);
                $key = ['batch_id'=>$locked->id,'transformer_id'=>$transformer->id,'reviewer_id'=>$request->user()->id,'field_key'=>$field['key']];
                if ($data['checked']) DB::table('mdb_review_checks')->updateOrInsert($key,['value_hash'=>$field['hash'],'created_at'=>now(),'updated_at'=>now()]);
                else DB::table('mdb_review_checks')->where($key)->delete();
                if ($data['checked']) $checked[$field['key']] = true;
                else unset($checked[$field['key']]);
            }
            app(AuditService::class)->record($request->user(),'mdb.verification_values_checked',$locked,[],['transformer_id'=>$transformer->id,'fields'=>array_column($data['fields'],'key'),'checked'=>$data['checked']]);

            return response()->json(['revision'=>$locked->revision,'checked'=>array_keys($checked)]);
        });
    }

    public function header(Request $request, SurveyBatch $batch, NetworkTransformer $transformer)
    {
        $this->prepare($request, $batch);
        abort_unless($transformer->batch_id === $batch->id, 404);

        return app(MdbOperatorEntryController::class)->header($request, $batch, $transformer);
    }

    public function row(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, EntryRow $row)
    {
        $this->prepare($request, $batch);
        abort_unless($transformer->batch_id === $batch->id && $row->transformer_id === $transformer->id, 404);

        return app(MdbOperatorEntryController::class)->save($request, $batch, $transformer, $row);
    }

    private function prepare(Request $request, SurveyBatch $batch): void
    {
        app(WorkflowAccess::class)->authorizeCorrection($request->user(), $batch);
        $request->validate(['correction_reason'=>'required|string|min:5|max:4000']);
        $request->attributes->set('mdb_verifier_correction', true);
    }
}
