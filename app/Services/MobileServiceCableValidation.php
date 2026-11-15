<?php

namespace App\Services;

use App\Services\Mdb\ApprovedWarehouse;
use App\Services\Mdb\EntryLookups;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileServiceCableValidation
{
    public function rules(): array
    {
        return [
            'service_cables' => 'sometimes|array|max:5000',
            'service_cables.*' => 'array:cable_id,pole_row_id,category,consumer_number,group,date,gps_waypoint,meter_number,consumer_reference,phase,conductor_code,measured_length_m,length_evidence,remarks',
            'service_cables.*.cable_id' => 'required|uuid|distinct',
            'service_cables.*.pole_row_id' => 'required|uuid',
            'service_cables.*.category' => ['required', Rule::in(array_diff(array_keys(EntryLookups::CONSUMERS), ['pv']))],
            'service_cables.*.consumer_number' => 'required|integer|min:1|max:1000000',
            'service_cables.*.group' => ['required', 'string', 'regex:/^\d{2}$/D'],
            'service_cables.*.date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'service_cables.*.gps_waypoint' => ['required', 'string', 'regex:/^\d{3}$/D'],
            'service_cables.*.meter_number' => 'nullable|string|max:255',
            'service_cables.*.consumer_reference' => 'nullable|string|max:255',
            'service_cables.*.phase' => 'nullable|in:R,Y,B,RYB',
            'service_cables.*.conductor_code' => ['nullable', Rule::in(ApprovedWarehouse::conductors())],
            'service_cables.*.measured_length_m' => 'nullable|numeric|gt:0|max:100000',
            'service_cables.*.length_evidence' => 'required_with:service_cables.*.measured_length_m|nullable|string|max:4000',
            'service_cables.*.remarks' => 'nullable|string|max:4000',
        ];
    }

    public function assertValid(array $data): void
    {
        Validator::make($data, $this->rules())->validate();
        $errors = $slots = $meters = [];
        $identity = fn ($row) => ($row['group'] ?? '').'|'.($row['date'] ?? '').'|'.($row['gps_waypoint'] ?? '');
        $rows = $data['rows'] ?? [];
        foreach ($data['service_cables'] ?? [] as $i => $cable) {
            $pole = collect($rows)->firstWhere('row_id', $cable['pole_row_id']);
            $prefix = "service_cables.$i";
            if (!$pole || ($pole['se'] ?? null) !== 'E') {
                $errors[$prefix.'.pole_row_id'] = 'Choose an E pole from this survey.';
                continue;
            }
            if ($cable['consumer_number'] > ($pole['consumers'][$cable['category']] ?? 0)) {
                $errors[$prefix.'.consumer_number'] = 'Consumer number exceeds the recorded category count at this E pole.';
            }
            $slot = $cable['pole_row_id'].':'.$cable['category'].':'.$cable['consumer_number'];
            if (isset($slots[$slot])) $errors[$prefix.'.consumer_number'] = 'This consumer already has a meter connection.';
            $slots[$slot] = true;
            $key = $identity($cable);
            if (isset($meters[$key]) || collect($rows)->contains(fn ($row) => $identity($row) === $key)) {
                $errors[$prefix.'.gps_waypoint'] = 'This waypoint is already used by a pole or meter.';
            }
            $meters[$key] = true;
            if (!empty($cable['phase'])) {
                $single = in_array($cable['category'], EntryLookups::SINGLE_PHASE_CONSUMERS, true);
                if (($single && strlen($cable['phase']) !== 1) || (!$single && $cable['phase'] !== 'RYB')) {
                    $errors[$prefix.'.phase'] = 'The meter phase must match its consumer category.';
                }
                $pair = collect(\App\Support\FieldSurveyPairs::groups($rows))->first(fn ($indices) => collect($indices)->contains(fn ($index) => ($rows[$index]['row_id'] ?? null) === $cable['pole_row_id']));
                $start = collect($pair ?? [])->map(fn ($index) => $rows[$index])->firstWhere('se', 'S');
                foreach (str_split($cable['phase']) as $phase) {
                    $value = $start['conductor_'.strtolower($phase)] ?? null;
                    if (!$value || $value === '__none__') $errors[$prefix.'.phase'] = 'This phase has no recorded conductor at the section S pole.';
                }
            }
        }
        foreach (collect($data['service_cables'] ?? [])->groupBy(fn ($c) => $c['pole_row_id'].':'.$c['category']) as $cables) {
            $cable = $cables->first();
            if (!in_array($cable['category'], EntryLookups::SINGLE_PHASE_CONSUMERS, true)) continue;
            $pole = collect($rows)->firstWhere('row_id', $cable['pole_row_id']);
            $phases = $pole['consumer_phases'][$cable['category']] ?? [];
            if (!collect($phases)->contains(fn ($n) => $n !== null && $n !== '')) continue;
            foreach (['R', 'Y', 'B'] as $phase) {
                if ($cables->where('phase', $phase)->count() > ($phases[$phase] ?? 0)) {
                    $errors['service_cables'] = "Recorded $phase meter connections exceed the E pole phase count.";
                }
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }
}
