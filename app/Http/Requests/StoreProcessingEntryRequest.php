<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProcessingEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->organization_id;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.assignment_id' => ['required', 'integer', 'exists:mdb_processing_assignments,id', 'distinct'],
            'items.*.mdb_processed' => ['required', 'integer', 'min:1'],
            'items.*.output_drive_url' => ['nullable', 'url:http,https', 'max:2000'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
