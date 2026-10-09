<?php

namespace App\Http\Requests;

use App\Support\ConductorPhase;
use App\Support\IntersectionFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncFieldSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $submitted = $this->input('status') === 'submitted';
        $paper = $submitted ? 'required' : 'nullable';
        $rules = [
            'client_uuid' => ['required', 'uuid'],
            'base_revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'survey_team_id' => ['required', 'integer'],
            'feeder_id' => ['required', 'integer'],
            'transformer_id' => ['nullable', 'integer'],
            'transformer_code' => [$paper, 'string', 'max:100'],
            'survey_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['draft', 'submitted'])],
            'header' => ['present', 'array:substation,division,sub_division,sub_division_code,transformer_make,inspectors,location,capacity_kva,mounting,duty'],
            'header.capacity_kva' => [$paper, 'numeric', 'gt:0', 'max:10000000'],
            'header.inspectors' => [$paper, 'string', 'max:200'],
            'header.location' => ['nullable', 'string', 'max:500'],
            'header.mounting' => ['nullable', Rule::in(['S.Pole', 'D.Pole', 'Pad'])],
            'header.duty' => ['nullable', Rule::in(['General Duty', 'Dedicated'])],
            'rows' => ['present', 'array', $submitted ? 'min:1' : 'min:0', 'max:500'],
            'rows.*' => ['array:se,group,date,gps_waypoint,latitude,longitude,gps_accuracy_m,phase,conductor_r,conductor_y,conductor_b,conductor_neutral,equipment_type,pole_class,pole_height_ft,consumers,intersection,remarks'],
            'rows.*.se' => [$paper, Rule::in(['S', 'E'])],
            'rows.*.group' => ['nullable', 'string', 'max:20'],
            'rows.*.date' => [$paper, 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows.*.gps_waypoint' => [$paper, 'string', 'max:50'],
            'rows.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'rows.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'rows.*.gps_accuracy_m' => ['nullable', 'numeric', 'between:0,100000000'],
            'rows.*.phase' => ['nullable', 'string', 'max:30'],
            'rows.*.equipment_type' => ['nullable', 'string', 'max:50'],
            'rows.*.pole_class' => ['nullable', 'string', 'max:50'],
            'rows.*.pole_height_ft' => ['nullable', 'numeric', 'between:0,1000000'],
            'rows.*.consumers' => ['sometimes', 'array:rs,rl,sc,lc,si,li,pb,ag,st'],
            'rows.*.intersection' => ['nullable', 'boolean'],
            'rows.*.remarks' => ['nullable', 'string', 'max:1000'],
            'solar' => ['present', 'array', 'max:100'],
            'solar.*' => ['array:consumer_reference,installed_pv_kw,remarks'],
            'solar.*.consumer_reference' => ['nullable', 'string', 'max:100'],
            'solar.*.installed_pv_kw' => ['nullable', 'numeric', 'between:0,10000000'],
            'solar.*.remarks' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (['substation', 'division', 'sub_division', 'sub_division_code', 'transformer_make'] as $key) {
            $rules['header.'.$key] = ['nullable', 'string', 'max:200'];
        }
        foreach (['r', 'y', 'b', 'neutral'] as $key) {
            $rules['rows.*.conductor_'.$key] = ['nullable', 'string', 'max:100'];
        }
        foreach (['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'] as $key) {
            $rules['rows.*.consumers.'.$key] = ['nullable', 'integer', 'between:0,1000000'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > 2 * 1024 * 1024, 413, 'Survey payload exceeds 2 MB.');
        $rows = $this->input('rows');
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    $row['phase'] = ConductorPhase::fromRow($row);
                    if (array_key_exists('intersection', $row)) {
                        $row['intersection'] = IntersectionFlag::normalize($row['intersection']);
                    }
                }
            }
            unset($row);
            $this->merge(['rows' => $rows]);
        }
    }
}
