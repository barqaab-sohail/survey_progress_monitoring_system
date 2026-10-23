<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFieldSurveyRequest;
use App\Models\FieldSurveyAttachment;
use App\Services\DriveStorage\FileStorage;
use App\Services\FieldSurveyAccess;
use App\Services\FieldSurveyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldSurveyController extends Controller
{
    public function index(Request $request, FieldSurveyAccess $access): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'submitted'])]]);
        $query = $access->visible($request->user())->with(['feeder', 'team', 'collector'])->withCount('attachments');
        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(fn ($query) => $query->where('transformer_code', 'like', '%'.$search.'%')
                ->orWhereHas('feeder', fn ($feeder) => $feeder->where('feeder_code', 'like', '%'.$search.'%')->orWhere('feeder_name', 'like', '%'.$search.'%')));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $surveys = $query->latest('survey_date')->latest('id')->paginate(20)->withQueryString();

        return view('field-surveys.index', compact('surveys', 'filters') + ['canManage' => $access->canManage($request->user())]);
    }

    public function show(Request $request, string $clientUuid, FieldSurveyAccess $access): View
    {
        $survey = $access->visible($request->user())->with(['feeder', 'team', 'collector', 'attachments'])->where('client_uuid', $clientUuid)->firstOrFail();

        return view('field-surveys.show', compact('survey') + ['canManage' => $access->canManage($request->user())]);
    }

    public function edit(Request $request, string $clientUuid, FieldSurveyAccess $access): View
    {
        $survey = $access->visible($request->user())->with(['feeder', 'team', 'collector', 'attachments'])->where('client_uuid', $clientUuid)->firstOrFail();
        $access->authorizeManagement($request->user(), $survey);

        return view('field-surveys.edit', compact('survey'));
    }

    public function update(UpdateFieldSurveyRequest $request, string $clientUuid, FieldSurveyAccess $access, FieldSurveyService $service): RedirectResponse
    {
        $survey = $access->visible($request->user())->where('client_uuid', $clientUuid)->firstOrFail();
        $service->updateOnServer($request->user(), $survey, $request->validated());

        return to_route('field-surveys.show', $clientUuid)->with('success', 'Survey corrections saved. A new server revision was created.');
    }

    public function destroy(Request $request, string $clientUuid, FieldSurveyAccess $access, FieldSurveyService $service): RedirectResponse
    {
        $survey = $access->visible($request->user())->where('client_uuid', $clientUuid)->firstOrFail();
        $access->authorizeManagement($request->user(), $survey);
        $data = $request->validate(['base_revision' => ['required', 'integer', 'min:1']]);
        $service->deleteOnServer($request->user(), $survey, (int) $data['base_revision']);

        return to_route('field-surveys.index')->with('success', 'Survey deleted from the active list. Its history and uploaded evidence have been retained.');
    }

    public function download(Request $request, string $clientUuid, FieldSurveyAccess $access): BinaryFileResponse
    {
        $attachment = FieldSurveyAttachment::where('client_uuid', $clientUuid)->firstOrFail();
        abort_unless($access->visible($request->user())->whereKey($attachment->field_survey_id)->exists(), 404);

        return app(FileStorage::class)->response($attachment->driveStoredFile, 'local', $attachment->path,
            $attachment->driveStoredFile?->original_name ?? $attachment->client_uuid, $attachment->mime_type, $request->boolean('download'));
    }
}
