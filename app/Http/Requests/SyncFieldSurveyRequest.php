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
            'rows.*' => ['array:entry_version,se,group,date,gps_waypoint,latitude,longitude,gps_accuracy_m,phase,conductor_r,conductor_y,conductor_b,conductor_neutral,equipment_type,pole_class,pole_height_ft,consumers,intersection,remarks'],
            'rows.*.entry_version' => ['sometimes', 'integer', Rule::in([2])],
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
            'solar.*' => ['array:consumer_reference,service_load_kw,installed_pv_kw,meter_number,remarks'],
            'solar.*.consumer_reference' => ['nullable', 'string', 'max:255'],
            'solar.*.service_load_kw' => ['nullable', 'numeric', 'between:0,1000000'],
            'solar.*.meter_number' => ['nullable', 'string', 'max:255'],
            'solar.*.installed_pv_kw' => ['nullable', 'numeric', 'between:0,10000000'],
            'solar.*.remarks' => ['nullable', 'string', 'max:4000'],
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

        // Older clients and saved free-text rows remain readable and editable.
        $equipment = null;
        foreach (is_array($this->input('rows')) ? $this->input('rows') : [] as $index => $row) {
            if (is_array($row) && ($row['entry_version'] ?? null) === 2) {
                $rules["rows.$index.group"] = ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/D'];
                $rules["rows.$index.gps_waypoint"] = [$paper, 'string', 'max:50', 'regex:/^[0-9]+$/D'];
                $equipment ??= array_keys(app(\App\Services\Mdb\EntryLookups::class)->options(
                    \App\Models\Feeder::whereKey($this->integer('feeder_id'))->value('project_id'))['equipment_types']);
                $rules["rows.$index.equipment_type"] = ['nullable', 'string', Rule::in($equipment)];
            }
        }

        foreach (is_array($this->input('solar')) ? $this->input('solar') : [] as $index => $pv) {
            if (is_array($pv) && (array_key_exists('service_load_kw', $pv) || array_key_exists('meter_number', $pv))) {
                $rules["solar.$index.installed_pv_kw"] = ['nullable', 'numeric', 'between:0,1000000'];
            }
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
