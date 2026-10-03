<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProcessingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feeder_id' => ['required', 'integer', 'exists:feeders,id'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'processing_team_id' => ['nullable', 'integer', 'exists:processing_teams,id'],
            'assigned_quantity' => ['required', 'integer', 'min:1'],
            'assignment_date' => ['required', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:assignment_date'],
            'drive_url' => ['nullable', 'url:http,https', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
