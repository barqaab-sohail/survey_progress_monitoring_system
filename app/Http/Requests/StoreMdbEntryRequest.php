<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMdbEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.feeder_id' => ['required', 'integer', 'exists:feeders,id', 'distinct'],
            'items.*.mdb_files_created' => ['required', 'integer', 'min:1'],
            'items.*.drive_url' => ['nullable', 'url:http,https', 'max:2000'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
