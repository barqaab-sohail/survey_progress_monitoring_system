<?php

namespace App\Services\Mdb;

use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function state(SurveyBatch $batch, NetworkTransformer $transformer, int $reviewerId): array
    {
        $batch->loadMissing('sources', 'waypoints');
        $transformer->load('entryRows');
        $lookups = app(EntryLookups::class)->options();
        $pdfs = $batch->sources->where('kind', 'pdf')->mapWithKeys(fn ($s) => [$s->id => $s->original_name])->all();
        $gpx = $batch->sources->where('kind', 'gpx')->mapWithKeys(fn ($s) => [$s->id => $s->original_name])->all();
        $field = function ($path, $label, $value, $type = 'text', $options = [], $group = 'Identity', $editable = true) {
            return compact('path', 'label', 'value', 'type', 'options', 'group', 'editable');
        };
        $fields = [$field('code', 'Transformer number', $transformer->code, 'text', [], 'Header', false),
            $field('capacity_kva', 'Capacity (kVA)', $transformer->capacity_kva, 'capacity', [], 'Header')];
        foreach (['substation_name'=>'Substation', 'feeder_name'=>'Feeder', 'feeder_identifier'=>'Feeder code', 'division'=>'Division',
            'subdivision'=>'Subdivision', 'subdivision_code'=>'Subdivision code', 'make'=>'Make', 'inspector'=>'Inspector',
            'location'=>'Location', 'survey_date'=>'Survey date', 'team_group'=>'Group / team', 'client_transformer_information'=>'Client transformer information'] as $key => $label) {
            $fields[] = $field('header.'.$key, $label, $transformer->header[$key] ?? null, $key === 'survey_date' ? 'date' : 'text', [], 'Header');
        }
        $fields[] = $field('header.mounting', 'Mounting', $transformer->header['mounting'] ?? null, 'select', array_combine(['Single Pole', 'Double Pole', 'Pad'], ['Single Pole', 'Double Pole', 'Pad']), 'Header');
        $fields[] = $field('header.service_category', 'Service category', $transformer->header['service_category'] ?? null, 'select', ['General Duty'=>'General Duty','Dedicated'=>'Dedicated'], 'Header');
        $page = collect($transformer->header['pages'] ?? [])->firstWhere('role', 'header');
        $fields[] = $field('source_pdf_id', 'Source PDF', $transformer->header['pdf_source_id'] ?? $page['source_pdf_id'] ?? null, 'select', $pdfs, 'Header');
        $fields[] = $field('source_page', 'Starting PDF page', $transformer->header['pdf_start_page'] ?? $page['page'] ?? null, 'number', [], 'Header');
        $location = app(SurveyWaypoint::class)->transformerLocation(['header'=>$transformer->header, 'entry_rows'=>$transformer->entryRows->map->surveyData()->all()], $batch->waypoints->toArray());
        $point = $batch->waypoints->firstWhere('id', $location['source_waypoint_id'] ?? null);
        $fields[] = $field('transformer_gps', 'Transformer GPS (first E)', $point?->name, 'text', [], 'Header', false);
        $header = ['title'=>$transformer->code.' — transformer header', 'fields'=>$this->identify('header', $fields)];
        $pairs = [];
        foreach ($transformer->entryRows->groupBy('pair_number') as $number => $rows) {
            $pair = ['number'=>$number, 'rows'=>[]];
            foreach ($rows as $row) {
                $data = $row->surveyData();
                $fields = [$field('group_number','Group',$row->group_number), $field('row_date','Date (DDMMYY)',$data['row_date'],'survey-date'),
                    $field('waypoint_reference','GPS number',$row->waypoint_reference),
                    $field('source_pdf_id','Source PDF',$row->source_pdf_id,'select',$pdfs), $field('source_page','PDF page',$row->source_page,'number'),
                    $field('gpx_source_id','GPX source',$row->gpx_source_id,'select',$gpx)];
                if ($row->designation === 'S' && $row->pair_number == $transformer->entryRows->min('pair_number') && empty($row->gpx_source_id)) {
                    $fields[5]['empty_label'] = 'KMZ reference (first S)';
                    $fields[5]['editable'] = false;
                }
                foreach (['R','Y','B','N'] as $phase) {
                    $value = $row->conductors[$phase] ?? null;
                    $fields[] = $field('conductors.'.$phase, $phase === 'N' ? 'Neutral' : $phase, $value === '' ? '__none__' : $value,
                        'select', ['__none__'=>'None / Not Present'] + $lookups['conductors'], 'Electrical');
                }
                $fields[] = $field('equipment_type','Type',$row->equipment_type,'select',$lookups['equipment_types'],'Electrical');
                $fields[] = $field('pole_class','Pole class',$row->pole_class,'select',$lookups['pole_classes'],'Electrical');
                $heightInFeet = $row->pole_height === null ? null : round((float) $row->pole_height * ($row->pole_height_unit === 'm' ? 3.280839895 : 1), 4);
                $fields[] = $field('pole_height','Pole / tower height (ft)',$heightInFeet,'height',[],'Electrical');
                $fields[] = $field('pole_height_unit','Height unit','ft','text',[],'Electrical',false);
                foreach (EntryLookups::CONSUMERS as $key => $description) {
                    $fields[] = $field('consumers.'.$key,strtoupper($key).' — '.$description,$row->consumers[$key] ?? null,'number',[],'Consumers');
                }
                $fields[] = $field('intersection','Intersection',$row->intersection,'boolean',[],'Consumers');
                foreach ($row->pv_details ?? [] as $index => $pv) {
                    foreach (['reference'=>'Consumer reference','service_load_kw'=>'Service load (kW)','installed_capacity_kw'=>'Solar capacity (kW)','meter_number'=>'Meter Number','remarks'=>'Remarks'] as $key=>$label) {
                        $fields[] = $field('pv_details.'.$index.'.'.$key,'PV '.($index+1).' '.$label,$pv[$key] ?? null,str_ends_with($key,'_kw') ? 'number' : 'text',[],'PV');
                    }
                }
                $pair['rows'][$row->designation] = ['id'=>$row->id, 'designation'=>$row->designation, 'data'=>$data,
                    'fields'=>$this->identify('row.'.$row->id, $fields)];
            }
            $pairs[] = $pair;
        }
        $checks = DB::table('mdb_review_checks')->where('transformer_id',$transformer->id)->where('reviewer_id',$reviewerId)->pluck('value_hash','field_key');
        $all = collect($header['fields'])->concat(collect($pairs)->flatMap(fn ($pair) => collect($pair['rows'])->flatMap(fn ($row) => $row['fields'])));
        $checked = $all->filter(fn ($field) => ($checks[$field['key']] ?? null) === $field['hash'])->pluck('key')->values()->all();

        return ['batch_id'=>$batch->id,'transformer_id'=>$transformer->id,'revision'=>$batch->revision,'header'=>$header,'pairs'=>$pairs,
            'checked'=>$checked,'total'=>$all->count(), 'issues'=>array_values(array_filter(app(EntryService::class)->issues($batch), fn ($issue) => $issue['transformer_id'] === $transformer->id)), 'entry_settings'=>app(SurveyWaypoint::class)->settings($batch->project_id)];
    }

    private function identify(string $prefix, array $fields): array
    {
        // A correction makes this complete header/row require a fresh check.
        $hash = hash('sha256', json_encode(array_column($fields, 'value', 'path'), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
        foreach ($fields as &$field) {
            $field['key'] = $prefix.'.'.$field['path'];
            $field['hash'] = $hash;
        }

        return $fields;
    }
}
