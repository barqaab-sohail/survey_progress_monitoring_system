<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncHtNetworkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        abort_if(strlen($this->getContent()) > 6 * 1024 * 1024, 413, 'HT update exceeds 6 MB.');

        return [
            'client_uuid' => 'required|uuid', 'base_revision' => 'required|integer|min:0',
            'survey_team_id' => 'required|integer', 'feeder_id' => 'required|integer',
            'transformer_code' => 'required|string|max:100',
            'kind' => ['required', Rule::in(['missing_transformer', 'ht_extension'])],
            'capacity_kva' => 'required|numeric|gt:0|max:10000000',
            'make' => 'required|string|max:200', 'location' => 'required|string|max:500',
            'mounting' => ['required', Rule::in(['S.Pole', 'D.Pole', 'Pad'])],
            'phase' => ['required', Rule::in(['R', 'Y', 'B', 'R-Y', 'Y-B', 'R-B', 'R-Y-B'])],
            'upstream_feature_id' => 'nullable|string|max:200',
            'upstream_reference' => 'required|string|max:500',
            'baseline_sha256' => 'nullable|string|size:64',
            'observed_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'nodes' => 'required|array|list|min:2|max:200',
            'nodes.*' => 'array:waypoint,pole_type,pole_height,latitude,longitude,conductor_r,conductor_y,conductor_b',
            'nodes.*.waypoint' => 'required|string|max:100|distinct',
            'nodes.*.pole_type' => 'nullable|string|max:100',
            'nodes.*.pole_height' => 'nullable|numeric|min:0|max:1000',
            'nodes.*.latitude' => 'nullable|numeric|between:-90,90|required_with:nodes.*.longitude',
            'nodes.*.longitude' => 'nullable|numeric|between:-180,180|required_with:nodes.*.latitude',
            'nodes.*.conductor_r' => 'nullable|string|max:100',
            'nodes.*.conductor_y' => 'nullable|string|max:100',
            'nodes.*.conductor_b' => 'nullable|string|max:100',
            'remarks' => 'required|string|max:4000',
            'photos' => 'required|array|list|min:1|max:3',
            'photos.*' => 'required|string|max:1500000',
        ];
    }
}
