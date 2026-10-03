<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResubmitSurveyItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transformers_surveyed' => ['required', 'integer', 'min:1'],
            'drive_url' => ['nullable', 'url:http,https', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
