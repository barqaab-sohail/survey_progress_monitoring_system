<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncFieldSurveyRequest;
use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\Transformer;
use App\Models\SurveyorAssignment;
use App\Services\DriveStorage\FileStorage;
use App\Services\FieldSurveyAccess;
use App\Services\FieldSurveyService;
use App\Services\Mdb\EntryLookups;
use App\Services\Mdb\SurveyWaypoint;
use App\Support\FieldTransformerIdentity;
use App\Support\SurveyTeamGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldSurveyApiController extends Controller
{
    public function bootstrap(Request $request, FieldSurveyAccess $access): JsonResponse
    {
        $user = $request->user();
        $teams = $access->teams($user)->orderBy('id')->get(['id', 'code', 'name', 'project_id']);
        $feeders = $access->feeders($user)->with(['gridStation', 'division', 'subDivision', 'assignments' => function ($query) use ($access, $teams): void {
            $access->activeAssignments($query->getQuery());
            $query->whereIn('survey_team_id', $teams->pluck('id'));
        }])->orderBy('feeder_code')->get();
        $transformers = Transformer::whereIn('feeder_id', $feeders->pluck('id'))->orderBy('transformer_code')
            ->get(['id', 'feeder_id', 'transformer_code', 'capacity_kva', 'equipment_make', 'equipment_location']);
        $saved = FieldSurvey::whereIn('feeder_id', $feeders->pluck('id'))->get(['feeder_id', 'transformer_id', 'transformer_key']);
        $claimedReferences = $claimedCodes = [];
        foreach ($saved as $survey) {
            if ($survey->transformer_id !== null) {
                $claimedReferences[$survey->feeder_id.':'.$survey->transformer_id] = true;
            }
            if ($survey->transformer_key !== null) {
                $claimedCodes[$survey->feeder_id.':'.$survey->transformer_key] = true;
            }
        }
        $transformers = $transformers->reject(fn ($t) => isset($claimedReferences[$t->feeder_id.':'.$t->id])
            || isset($claimedCodes[$t->feeder_id.':'.FieldTransformerIdentity::key($t->transformer_code)]))->values();
        $lookups = $feeders->pluck('project_id')->unique()->mapWithKeys(fn ($id) => [$id => app(EntryLookups::class)->options($id)]);
        $settings = $feeders->pluck('project_id')->unique()->mapWithKeys(fn ($id) => [$id => app(SurveyWaypoint::class)->settings($id)]);
        $personalTasks = SurveyorAssignment::where('surveyor_id', $user->id)->whereIn('feeder_id', $feeders->modelKeys())
            ->whereIn('status', ['assigned', 'in_progress', 'draft', 'returned', 'submitted'])->get()->groupBy('feeder_id');

        return response()->json([
            'user' => $user->only(['id', 'name']), 'teams' => $teams->map(fn ($team) => array_merge($team->toArray(), ['group_identifier' => SurveyTeamGroup::identifier($team)])),
            'feeders' => $feeders->map(fn ($feeder) => [
                'id' => $feeder->id, 'feeder_code' => $feeder->feeder_code, 'feeder_name' => $feeder->feeder_name,
                'entry_lookups' => $lookups[$feeder->project_id], 'entry_settings' => $settings[$feeder->project_id],
                'grid_station_name' => $feeder->gridStation?->name, 'division_name' => $feeder->division?->name,
                'sub_division_name' => $feeder->subDivision?->name, 'sub_division_code' => $feeder->subDivision?->code,
                'team_ids' => $feeder->assignments->filter(fn ($assignment) => $teams->firstWhere('id', $assignment->survey_team_id)?->project_id === $feeder->project_id)->pluck('survey_team_id')->unique()->values(),
                'tasks' => ($personalTasks[$feeder->id] ?? collect())->map(fn ($task) => ['assignment_id' => $task->assignment_uuid,
                    'team_id' => $task->survey_team_id, 'assigned_at' => $task->assigned_at?->toDateString(), 'deadline' => $task->deadline?->toDateString(),
                    'priority' => $task->priority, 'instructions' => $task->instructions, 'status' => $task->status])->values(),
            ]), 'transformers' => $transformers,
        ]);
    }

    public function sync(SyncFieldSurveyRequest $request, FieldSurveyService $service): JsonResponse
    {
        [$survey, $created] = $service->sync($request->user(), $request->validated());

        return response()->json($survey->only(['id', 'client_uuid', 'revision', 'status']), $created ? 201 : 200);
    }

    public function attachment(Request $request, string $clientUuid, FieldSurveyService $service): JsonResponse
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 11 * 1024 * 1024, 413, 'Attachment exceeds 10 MB.');
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'row_id' => ['nullable', 'uuid'], 'kind' => ['required', Rule::in(['photo', 'sketch'])], 'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240']]);
        $survey = FieldSurvey::where('client_uuid', $clientUuid)->firstOrFail();
        [$attachment, $created] = $service->attach($request->user(), $survey, $data['client_uuid'], $data['kind'], $request->file('file'), $data['row_id'] ?? null);

        return response()->json($attachment->only(['id', 'client_uuid', 'row_id']), $created ? 201 : 200);
    }

    public function download(Request $request, string $clientUuid, FieldSurveyAccess $access): BinaryFileResponse
    {
        $attachment = FieldSurveyAttachment::with('survey')->where('client_uuid', $clientUuid)->firstOrFail();
        $access->authorizeOwner($request->user(), $attachment->survey);

        return app(FileStorage::class)->response($attachment->driveStoredFile, 'local', $attachment->path,
            $attachment->driveStoredFile?->original_name ?? $attachment->client_uuid, $attachment->mime_type, $request->boolean('download'));
    }
}
