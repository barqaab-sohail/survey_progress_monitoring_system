<?php

namespace App\Http\Controllers;

use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\ServiceCable;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\ServiceCableService;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdbServiceCableController extends Controller
{
    public function configure(Request $request, SurveyBatch $batch, NetworkTransformer $transformer)
    {
        $this->authorizeFeature($request, $batch, $transformer);
        $data = $request->validate(['revision' => 'required|integer', 'enabled' => 'required|boolean']);

        return $this->mutate($request, $batch, $transformer, function () use ($transformer, $data) {
            if (! $data['enabled'] && $transformer->serviceCables()->exists()) {
                throw ValidationException::withMessages(['enabled' => 'Remove the meter records before disabling service-cable entry.']);
            }
            $transformer->update(['service_cables_enabled' => $data['enabled']]);
        });
    }

    public function save(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, ?ServiceCable $cable = null)
    {
        $this->authorizeFeature($request, $batch, $transformer);
        abort_unless(! $cable || $cable->transformer_id === $transformer->id, 404);
        $data = $request->validate(['revision' => 'required|integer'] + app(ServiceCableService::class)->rules());
        unset($data['revision']);
        $data['composite_identifier'] = app(SurveyWaypoint::class)->compose($data['group_number'], $data['row_date'], $data['waypoint_reference'],
            app(SurveyWaypoint::class)->settings($batch->project_id)['two_digit_year_start']);

        return $this->mutate($request, $batch, $transformer, function () use ($batch, $transformer, $cable, $data) {
            if (! $transformer->service_cables_enabled) {
                throw ValidationException::withMessages(['enabled' => 'Enable meter waypoint entry for this transformer first.']);
            }
            $points = $batch->waypoints()->get()->toArray();
            $sources = $batch->sources()->get()->toArray();
            $proposed = $transformer->serviceCables()->when($cable, fn ($q) => $q->whereKeyNot($cable->id))->get()->map->surveyData()->all();
            $proposed[] = $data;
            app(ServiceCableService::class)->assertValid(['id' => $transformer->id, 'service_cables_enabled' => true,
                'entry_rows' => $transformer->entryRows()->get()->map->surveyData()->all(), 'service_cables' => $proposed], $points, $sources);
            // A batch waypoint is one physical endpoint, even when different survey text is entered.
            if (ServiceCable::whereIn('transformer_id', $batch->transformers()->pluck('id'))
                ->when($cable, fn ($q) => $q->whereKeyNot($cable->id))
                ->where(fn ($q) => $q->where('meter_waypoint_id', $data['meter_waypoint_id'])->orWhere('composite_identifier', $data['composite_identifier']))->exists()) {
                throw ValidationException::withMessages(['meter_waypoint_id' => 'This meter waypoint is already linked to a consumer in this batch.']);
            }
            $cable ? $cable->update($data) : $transformer->serviceCables()->create($data);
        });
    }

    public function destroy(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, ServiceCable $cable)
    {
        $this->authorizeFeature($request, $batch, $transformer);
        abort_unless($cable->transformer_id === $transformer->id, 404);
        $request->validate(['revision' => 'required|integer']);

        return $this->mutate($request, $batch, $transformer, fn () => $cable->delete());
    }

    private function authorizeFeature(Request $request, SurveyBatch $batch, NetworkTransformer $transformer): void
    {
        abort_unless($request->user()->canEnterConsumerServiceCables(), 403);
        app(WorkflowAccess::class)->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id, 404);
    }

    private function mutate(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, callable $change)
    {
        $this->authorizeFeature($request, $batch, $transformer);
        DB::transaction(function () use ($request, $batch, $transformer, $change) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorizeFeature($request, $locked, $transformer);
            abort_unless($locked->revision === $request->integer('revision'), 409, 'Saved data changed. Refresh records before saving.');
            $transformer->refresh();
            $before = ['enabled' => $transformer->service_cables_enabled, 'cables' => $transformer->serviceCables()->get()->map->surveyData()->all()];
            $change();
            app(WorkflowAccess::class)->recordEntry($locked, $request->user());
            app(SnapshotService::class)->invalidate($locked);
            $header = $transformer->header ?? [];
            $header['manually_verified'] = false;
            $transformer->update(['header' => $header]);
            DB::table('mdb_review_checks')->where('transformer_id', $transformer->id)->delete();
            $locked->increment('revision');
            app(AuditService::class)->record($request->user(), 'mdb.service_cables_changed', $transformer, $before,
                ['enabled' => $transformer->service_cables_enabled, 'cables' => $transformer->serviceCables()->get()->map->surveyData()->all()]);
        });

        return response()->json(app(EntryService::class)->editorData($batch->fresh()));
    }
}
