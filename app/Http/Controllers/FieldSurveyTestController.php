<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncFieldSurveyRequest;
use App\Models\FieldSurveyTest;
use App\Models\FieldSurveyTestAttachment;
use App\Models\Transformer;
use App\Services\FieldSurveyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldSurveyTestController extends Controller
{
    public function index(): View
    {
        return view('field-survey-test.index');
    }

    public function records(Request $request): JsonResponse
    {
        $records = FieldSurveyTest::where('user_id', $request->user()->id)->with('attachments')->latest()->get();

        return response()->json($records->map(fn ($record) => [
            'data' => array_replace($record->payload, ['base_revision' => $record->revision]),
            'attachments' => $record->attachments->map(fn ($file) => ['uuid' => $file->client_uuid, 'kind' => $file->kind, 'url' => route('field-survey-test.download', $file->client_uuid), 'state' => 'synced']),
        ]));
    }

    public function sync(SyncFieldSurveyRequest $request, FieldSurveyAccess $access): JsonResponse
    {
        $data = $request->validated();
        $access->authorizeCollection($request->user(), $data['survey_team_id'], $data['feeder_id']);
        if (! empty($data['transformer_id']) && ! Transformer::where('feeder_id', $data['feeder_id'])->whereKey($data['transformer_id'])->exists()) {
            throw ValidationException::withMessages(['transformer_id' => 'Select a transformer belonging to this feeder.']);
        }
        $record = DB::transaction(function () use ($request, $data) {
            // Serialize new records as well as revisions for this account.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $record = FieldSurveyTest::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            $hashData = $data;
            unset($hashData['base_revision']);
            $hash = hash('sha256', json_encode($this->canonical($hashData), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if ($record) {
                abort_unless($record->user_id === $request->user()->id, 409, 'This test survey belongs to another account.');
                if (hash_equals($record->payload_hash, $hash)) {
                    return $record;
                }
                abort_unless($record->revision === (int) $data['base_revision'], 409, 'A newer test revision exists. Your browser draft is retained. Export it and review the server copy before retrying.');
            } else {
                abort_unless((int) $data['base_revision'] === 0, 409, 'New test surveys must start at revision zero.');
                $record = new FieldSurveyTest(['client_uuid' => $data['client_uuid'], 'user_id' => $request->user()->id, 'revision' => 0]);
            }
            $record->fill(['payload' => $data, 'payload_hash' => $hash, 'revision' => $record->revision + 1])->save();

            return $record;
        });

        return response()->json(['client_uuid' => $record->client_uuid, 'revision' => $record->revision, 'status' => $record->payload['status']]);
    }

    public function attach(Request $request, string $clientUuid, FieldSurveyAccess $access): JsonResponse
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 11 * 1024 * 1024, 413);
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['photo', 'sketch'])], 'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240']]);
        $survey = FieldSurveyTest::where('user_id', $request->user()->id)->where('client_uuid', $clientUuid)->firstOrFail();
        $access->authorizeCollection($request->user(), $survey->payload['survey_team_id'], $survey->payload['feeder_id']);
        $hash = hash_file('sha256', $request->file('file')->getRealPath());
        $attachment = DB::transaction(function () use ($survey, $data, $hash, $request) {
            $survey->newQuery()->whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $old = FieldSurveyTestAttachment::where('client_uuid', $data['client_uuid'])->first();
            if ($old) {
                abort_unless($old->field_survey_test_id === $survey->id && $old->file_hash === $hash && $old->kind === $data['kind'], 409, 'This attachment UUID already identifies different content.');

                return $old;
            }
            $path = $request->file('file')->store('field-survey-tests/'.$survey->client_uuid, 'local');
            abort_unless($path, 500, 'Unable to save attachment.');
            try {
                return $survey->attachments()->create(['client_uuid' => $data['client_uuid'], 'kind' => $data['kind'], 'path' => $path, 'mime_type' => $request->file('file')->getMimeType(), 'file_hash' => $hash]);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($path);
                throw $exception;
            }
        });

        return response()->json(['client_uuid' => $attachment->client_uuid, 'url' => route('field-survey-test.download', $attachment->client_uuid)]);
    }

    public function download(Request $request, string $clientUuid): BinaryFileResponse
    {
        $file = FieldSurveyTestAttachment::where('client_uuid', $clientUuid)->whereHas('survey', fn ($query) => $query->where('user_id', $request->user()->id))->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
