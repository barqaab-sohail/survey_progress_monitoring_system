<?php

namespace App\Http\Requests;

use App\Models\Feeder;
use App\Models\Mdb\Configuration;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\GenerationMode;
use App\Support\IntersectionFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class SaveTransformerMdbRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super_admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');
        if (! is_string($payload) || strlen($payload) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['payload' => 'Survey form data is missing or exceeds 2 MB. Enable JavaScript and try again.']);
        }
        try {
            $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => 'Survey form data could not be read.']);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw ValidationException::withMessages(['payload' => 'Survey form data must be an object.']);
        }
        if (isset($data['rows']) && is_array($data['rows'])) {
            foreach ($data['rows'] as &$row) {
                if (is_array($row) && array_key_exists('intersection', $row)) {
                    $row['intersection'] = IntersectionFlag::normalize($row['intersection']);
                }
            }
            unset($row);
        }
        if (is_array($data['export_settings'] ?? null)) {
            $data['export_settings']['consumer_kva'] = array_map(fn ($rate) => $rate['kva'], EngineeringConfiguration::consumerRates());
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $projectId = Feeder::whereKey($this->input('feeder_id'))->value('project_id');
        $simple = $projectId && GenerationMode::simple(Configuration::where('project_id', $projectId)->first());
        $engineeringRequired = $simple ? 'nullable' : 'required';
        // Share the paper layout validation with Android, without its team/revision sync protocol.
        $paper = SyncFieldSurveyRequest::create('/', 'POST', ['status' => 'draft']);
        $rules = $paper->rules();
        foreach (['client_uuid', 'base_revision', 'survey_team_id', 'transformer_id', 'status'] as $field) {
            unset($rules[$field]);
        }
        $rules['transformer_code'] = ['required', 'string', 'max:100'];
        $rules['feeder_id'] = ['required', 'integer', Rule::exists('feeders', 'id')->where('status', 'active')];
        $rules['revision'] = ['required', 'integer', 'min:0'];
        $rules['header.capacity_kva'] = ['required', 'numeric', 'gt:0', 'max:1000000'];
        $rules['rows.*.pole_height_ft'] = ['required', 'numeric', 'gt:0', 'max:10000'];
        $rules['source_field_survey_id'] = ['nullable', 'integer', 'exists:field_surveys,id'];
        $rules['pdf'] = ['nullable', 'file', 'mimes:pdf', 'max:51200'];
        $rules['gpx'] = ['nullable', 'file', 'extensions:gpx', 'max:10240'];
        $rules['export_settings'] = ['required', 'array:transformer_waypoints,transformer_latitude,transformer_longitude,utm_zone,frequency,nominal_kv,transformer_type,configuration_id,phase_spacing_cm,neutral_spacing_cm,conductor_height_m,consumer_kva,blank_consumers_zero,engineering_reviewed'];
        foreach (['transformer_waypoints', 'transformer_type', 'configuration_id'] as $key) {
            $rules['export_settings.'.$key] = ['nullable', 'string', 'max:200'];
        }
        $rules['export_settings.transformer_latitude'] = ['nullable', 'numeric', 'between:0,84'];
        $rules['export_settings.transformer_longitude'] = ['nullable', 'numeric', 'between:-180,180'];
        $rules['export_settings.utm_zone'] = [$engineeringRequired, 'integer', 'between:1,60'];
        $rules['export_settings.frequency'] = [$engineeringRequired, Rule::in([50, 60])];
        $rules['export_settings.nominal_kv'] = [$engineeringRequired, 'numeric', 'gt:0', 'max:500'];
        foreach (['phase_spacing_cm', 'neutral_spacing_cm', 'conductor_height_m'] as $key) {
            $rules['export_settings.'.$key] = [$engineeringRequired, 'numeric', 'gt:0', 'max:10000'];
        }
        $rules['export_settings.consumer_kva'] = [$simple ? 'nullable' : 'present', 'array:rs,rl,sc,lc,si,li,pb,ag,st'];
        foreach (['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'] as $category) {
            $rules['export_settings.consumer_kva.'.$category] = ['nullable', 'numeric', 'between:0,1000000'];
        }
        foreach (['blank_consumers_zero', 'engineering_reviewed'] as $key) {
            $rules['export_settings.'.$key] = [$engineeringRequired, 'boolean'];
        }

        return $rules;
    }
}
