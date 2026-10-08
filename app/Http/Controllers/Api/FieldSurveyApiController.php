<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncFieldSurveyRequest;
use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\Transformer;
use App\Services\FieldSurveyAccess;
use App\Services\FieldSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldSurveyApiController extends Controller
{
    public function bootstrap(Request $request, FieldSurveyAccess $access): JsonResponse
    {
        $user = $request->user();
        $teams = $access->teams($user)->orderBy('id')->get(['id', 'name', 'project_id']);
        $feeders = $access->feeders($user)->with(['gridStation', 'division', 'subDivision', 'assignments' => function ($query) use ($access, $teams): void {
            $access->activeAssignments($query->getQuery());
            $query->whereIn('survey_team_id', $teams->pluck('id'));
        }])->orderBy('feeder_code')->get();
        $transformers = Transformer::whereIn('feeder_id', $feeders->pluck('id'))->orderBy('transformer_code')
            ->get(['id', 'feeder_id', 'transformer_code', 'capacity_kva', 'equipment_make', 'equipment_location']);

        return response()->json([
            'user' => $user->only(['id', 'name']), 'teams' => $teams,
            'feeders' => $feeders->map(fn ($feeder) => [
                'id' => $feeder->id, 'feeder_code' => $feeder->feeder_code, 'feeder_name' => $feeder->feeder_name,
                'grid_station_name' => $feeder->gridStation?->name, 'division_name' => $feeder->division?->name,
                'sub_division_name' => $feeder->subDivision?->name, 'sub_division_code' => $feeder->subDivision?->code,
                'team_ids' => $feeder->assignments->filter(fn ($assignment) => $teams->firstWhere('id', $assignment->survey_team_id)?->project_id === $feeder->project_id)->pluck('survey_team_id')->unique()->values(),
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
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['photo', 'sketch'])], 'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240']]);
        $survey = FieldSurvey::where('client_uuid', $clientUuid)->firstOrFail();
        [$attachment, $created] = $service->attach($request->user(), $survey, $data['client_uuid'], $data['kind'], $request->file('file'));

        return response()->json($attachment->only(['id', 'client_uuid']), $created ? 201 : 200);
    }

    public function download(Request $request, string $clientUuid, FieldSurveyAccess $access): BinaryFileResponse
    {
        $attachment = FieldSurveyAttachment::with('survey')->where('client_uuid', $clientUuid)->firstOrFail();
        $access->authorizeOwner($request->user(), $attachment->survey);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $extension = match ($attachment->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', default => 'pdf'
        };

        return response()->file(Storage::disk('local')->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->client_uuid.'.'.$extension.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
