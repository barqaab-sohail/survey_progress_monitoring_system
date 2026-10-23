<?php

namespace App\Http\Requests;

use App\Services\FieldSurveyAccess;
use Illuminate\Validation\ValidationException;

class UpdateFieldSurveyRequest extends SyncFieldSurveyRequest
{
    protected function prepareForValidation(): void
    {
        $access = app(FieldSurveyAccess::class);
        $survey = $access->visible($this->user())->where('client_uuid', $this->route('clientUuid'))->firstOrFail();
        $access->authorizeManagement($this->user(), $survey);
        $json = $this->input('payload');
        if (! is_string($json) || strlen($json) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['payload' => 'The survey form is missing or exceeds 2 MB.']);
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload' => 'The survey form could not be read. Reload the page and try again.']);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw ValidationException::withMessages(['payload' => 'Invalid survey form.']);
        }
        $this->merge(array_replace($data, [
            'client_uuid' => $survey->client_uuid,
            'survey_team_id' => $survey->survey_team_id,
            'feeder_id' => $survey->feeder_id,
            'transformer_id' => $survey->transformer_id,
            'status' => $survey->status,
        ]));
        parent::prepareForValidation();
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['rows'][] = 'list';
        $rules['solar'][] = 'list';

        return $rules + ['correction_reason' => ['nullable', 'string', 'max:2000']];
    }
}
