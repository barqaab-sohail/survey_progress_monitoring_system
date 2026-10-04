<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSurveyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'survey_team_id' => ['nullable', 'integer', 'exists:survey_teams,id'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.feeder_id' => ['required', 'integer', 'exists:feeders,id', 'distinct'],
            'items.*.transformers_surveyed' => ['required', 'integer', 'min:1'],
            'items.*.drive_url' => ['required', 'url:http,https', 'max:2000'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['items.*.drive_url' => 'survey Google Drive URL'];
    }
}
