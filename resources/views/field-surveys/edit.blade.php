@extends('layouts.app')
@section('title', 'Correct LT Transformer Survey')
@section('content')
@php
    $payload = [
        'base_revision' => $survey->revision, 'transformer_code' => $survey->transformer_code,
        'survey_date' => $survey->survey_date->format('Y-m-d'), 'header' => $survey->header,
        'rows' => $survey->rows, 'solar' => $survey->solar, 'remarks' => $survey->remarks,
    ];
    $previousJson = old('payload', 'null');
    $previous = is_string($previousJson) ? json_decode($previousJson, true) : null;
    if (is_array($previous) && !array_is_list($previous)
        && is_array($previous['header'] ?? null) && is_array($previous['rows'] ?? null)
        && is_array($previous['solar'] ?? null)
        && collect($previous['rows'])->every(fn($row) => is_array($row))
        && collect($previous['solar'])->every(fn($row) => is_array($row))) $payload = $previous;
    $categories = ['rs','rl','sc','lc','si','li','pb','ag','st'];
@endphp
@push('styles')
<link rel="stylesheet" href="{{ asset('css/field-survey-correction.css').'?v='.filemtime(public_path('css/field-survey-correction.css')) }}">
@endpush
<div class="field-correction-page">

<div class="page-head"><div><h1>Correct LT Transformer Survey</h1><p>{{ $survey->transformer_code }} &middot; {{ $survey->feeder->feeder_name }} &middot; Server revision {{ $survey->revision }}</p></div><a class="btn btn-light" href="{{ route('field-surveys.show', $survey->client_uuid) }}">Cancel</a></div>
<div class="field-correction-layout">
<form id="field-correction-form" method="POST" action="{{ route('field-surveys.update', $survey->client_uuid) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="payload" id="field-correction-payload" value="{{ json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) }}">
    <noscript><p class="alert alert-danger">Enable JavaScript to edit this survey.</p></noscript>
    <section class="card"><h2>Transformer details</h2><div class="field-correction-fields">
        @include('field-surveys.edit-input', ['path'=>'transformer_code','label'=>'Transformer code'])
        @include('field-surveys.edit-input', ['path'=>'survey_date','label'=>'Survey date','type'=>'date'])
        @foreach(['substation'=>'Substation','division'=>'Division','sub_division'=>'Sub Division','sub_division_code'=>'Sub Division code','transformer_make'=>'Transformer make','inspectors'=>'Inspectors','location'=>'Location'] as $key=>$label)
            @include('field-surveys.edit-input', ['path'=>'header.'.$key,'label'=>$label,'type'=>'text'])
        @endforeach
        @include('field-surveys.edit-input', ['path'=>'header.capacity_kva','label'=>'Capacity (kVA)','type'=>'number'])
        @foreach(['mounting'=>['S.Pole','D.Pole','Pad'],'duty'=>['General Duty','Dedicated']] as $key=>$options)
        <label>{{ ucfirst($key) }}<select data-field="header.{{ $key }}"><option value="">Not recorded</option>@foreach($options as $option)<option @selected(data_get($payload, 'header.'.$key) === $option)>{{ $option }}</option>@endforeach</select></label>
        @endforeach
    </div></section>
    <section class="card field-row-tools" aria-label="Survey row navigation">
        <div class="field-row-tools-head"><h2>S/E observations ({{ count($payload['rows'] ?? []) }})</h2><button class="btn btn-primary" type="submit">Save corrections</button></div>
        <div class="field-row-tools-fields">
            <label>Find a row<input type="search" id="field-row-search" placeholder="Waypoint, group, date or S/E"></label>
            <label>Jump to row<select id="field-row-jump"><option value="">Choose a row</option>
                @foreach($payload['rows'] ?? [] as $index=>$row)<option value="{{ $index }}">Row {{ $index + 1 }} ? {{ $row['se'] ?? '-' }} ? GPS {{ $row['gps_waypoint'] ?? 'not recorded' }}</option>@endforeach
            </select></label>
        </div>
        <div class="field-row-pagination"><button type="button" class="btn btn-light" id="field-rows-prev">Previous</button><span id="field-rows-count" role="status" aria-live="polite"></span><button type="button" class="btn btn-light" id="field-rows-next">Next</button></div>
    </section>
    @foreach($payload['rows'] ?? [] as $index=>$row)
    <details class="card field-correction-row" id="survey-row-{{ $index }}" data-row-index="{{ $index }}" @if(collect($errors->keys())->contains(fn($key) => str_starts_with($key, "rows.$index."))) data-row-errors @endif @if($loop->first) open @endif>
        <summary><strong>Row {{ $index + 1 }} ? {{ $row['se'] ?? '-' }}</strong><span data-row-summary>GPS {{ $row['gps_waypoint'] ?? 'not recorded' }} ? Group {{ $row['group'] ?? '-' }} ? {{ $row['date'] ?? '-' }}</span><span class="field-row-edit-label">Edit row</span></summary>
        <div class="field-row-body"><div class="field-correction-fields">
        @include('field-surveys.edit-input', ['path'=>"rows.$index.group",'label'=>'Group identifier','type'=>'text'])
        @include('field-surveys.edit-input', ['path'=>"rows.$index.date",'label'=>'Date','type'=>'date'])
        @include('field-surveys.edit-input', ['path'=>"rows.$index.gps_waypoint",'label'=>'GPS waypoint','type'=>'text'])
        @if($row['waypoint_check_required'] ?? false)
        <input type="hidden" data-field="rows.{{ $index }}.waypoint_confirmation" value="{{ $row['waypoint_confirmation'] ?? '' }}">
        <label class="field-correction-checkbox"><input type="checkbox" data-waypoint-check="{{ $index }}" @checked(($row['waypoint_confirmation'] ?? null) === (($row['group'] ?? '').'|'.($row['date'] ?? '').'|'.($row['gps_waypoint'] ?? '')))> Waypoint checked</label>
        @endif
        @foreach(['r'=>'R','y'=>'Y','b'=>'B','neutral'=>'Neutral'] as $phase=>$label)
            @include('field-surveys.edit-input', ['path'=>"rows.$index.conductor_$phase",'label'=>'Conductor '.$label,'type'=>'text'])
        @endforeach
        @foreach(['equipment_type'=>'Equipment type','pole_class'=>'Pole class','remarks'=>'Row remarks'] as $key=>$label)
            @include('field-surveys.edit-input', ['path'=>"rows.$index.$key",'label'=>$label,'type'=>'text'])
        @endforeach
        @include('field-surveys.edit-input', ['path'=>"rows.$index.pole_height_ft",'label'=>'Pole height (ft)','type'=>'number'])
        <label class="field-correction-checkbox"><input data-field="rows.{{ $index }}.intersection" type="checkbox" @checked(in_array(\App\Support\IntersectionFlag::normalize($row['intersection'] ?? false), [true,1], true))> Intersection</label>
    </div><h3>Consumer counts</h3><div class="field-correction-fields field-correction-consumers">
        @foreach($categories as $category)
            @if(is_array($row['consumer_phases'][$category] ?? null))
                @foreach(['R','Y','B'] as $phase)
                    @include('field-surveys.edit-input', ['path'=>"rows.$index.consumer_phases.$category.$phase",'label'=>strtoupper($category).' / '.$phase,'type'=>'number'])
                @endforeach
            @else
                @include('field-surveys.edit-input', ['path'=>"rows.$index.consumers.$category",'label'=>strtoupper($category),'type'=>'number'])
            @endif
        @endforeach
    </div></div></details>
    @endforeach
    <p id="field-rows-empty" class="card" hidden>No rows match your search.</p>
    @foreach($payload['solar'] ?? [] as $index=>$pv)
    <section class="card"><h2>Solar entry {{ $index + 1 }}</h2><div class="field-correction-fields">
        @foreach(['consumer_reference'=>'Consumer reference','meter_number'=>'Meter number','remarks'=>'Remarks'] as $key=>$label)
            @include('field-surveys.edit-input', ['path'=>"solar.$index.$key",'label'=>$label,'type'=>'text'])
        @endforeach
        @include('field-surveys.edit-input', ['path'=>"solar.$index.service_load_kw",'label'=>'PV service load (kW)','type'=>'number'])
        @include('field-surveys.edit-input', ['path'=>"solar.$index.installed_pv_kw",'label'=>'Installed PV (kW)','type'=>'number'])
    </div></section>
    @endforeach
    <section class="card field-correction-notes">
        <h2>Remarks and corrections</h2>
        <label>Survey remarks<textarea data-field="remarks" rows="3">{{ $payload['remarks'] ?? '' }}</textarea></label>
        <label>Correction notes (optional)<textarea name="correction_reason" maxlength="2000" rows="2">{{ old('correction_reason') }}</textarea></label>
        <p>Save creates a new server revision and preserves the original photo links. The phone's synced copy remains read-only.</p>
        <div class="field-correction-actions"><a class="btn btn-light" href="{{ route('field-surveys.show', $survey->client_uuid) }}">Cancel</a><button type="submit" class="btn btn-primary">Save corrections</button></div>
    </section>
</form>
<aside class="card field-evidence"><h2>Uploaded evidence</h2>
    <label>Select image or sketch<select id="field-evidence-select" @disabled($survey->attachments->isEmpty())>
    @if($survey->attachments->isEmpty())<option>No uploaded evidence</option>@endif
    @foreach($survey->attachments as $file)
        @php $rowIndex = collect($survey->rows)->search(fn($r) => !empty($file->row_id) && ($r['row_id'] ?? null) === $file->row_id); @endphp
        <option value="{{ $loop->index }}">{{ ucfirst($file->kind) }} {{ $loop->iteration }} &middot; {{ $rowIndex === false ? 'Unlinked observation' : 'Row '.($rowIndex + 1) }}</option>
    @endforeach
    </select></label>
    @forelse($survey->attachments as $file)
        @php $rowIndex = collect($survey->rows)->search(fn($r) => !empty($file->row_id) && ($r['row_id'] ?? null) === $file->row_id); @endphp
        <div data-evidence="{{ $loop->index }}" @if(!$loop->first) hidden @endif>
            @if($rowIndex !== false)<p><a href="#survey-row-{{ $rowIndex }}">Go to row {{ $rowIndex + 1 }}</a></p>@endif
            @if(str_starts_with($file->mime_type, 'image/'))
                <img loading="lazy" src="{{ route('field-surveys.attachment', $file->client_uuid) }}" alt="Uploaded {{ $file->kind }}">
            @elseif($file->mime_type === 'application/pdf')
                <iframe loading="lazy" title="Uploaded PDF" src="{{ route('field-surveys.attachment', $file->client_uuid) }}" style="width:100%;height:60vh;border:0"></iframe>
            @endif
            <a class="btn btn-light" target="_blank" rel="noopener" href="{{ route('field-surveys.attachment', $file->client_uuid) }}">Open full size</a>
        </div>
    @empty<p>No images or sketches uploaded for this survey.</p>@endforelse
</aside>
</div>
</div>
<script src="{{ asset('js/field-survey-correction.js').'?v='.filemtime(public_path('js/field-survey-correction.js')) }}" defer></script>
<script>
(() => {
    const form = document.getElementById('field-correction-form');
    form.addEventListener('submit', () => {
        const hidden = document.getElementById('field-correction-payload');
        const data = JSON.parse(hidden.value);
        form.querySelectorAll('[data-field]').forEach(input => {
            const keys = input.dataset.field.split('.');
            const last = keys.pop();
            let target = data;
            keys.forEach(key => { target = target[key] ??= {}; });
            target[last] = input.type === 'checkbox' ? input.checked : input.value === '' ? null : input.type === 'number' ? Number(input.value) : input.value;
        });
        hidden.value = JSON.stringify(data);
    });
    document.getElementById('field-evidence-select').addEventListener('change', event => {
        document.querySelectorAll('[data-evidence]').forEach(panel => { panel.hidden = panel.dataset.evidence !== event.target.value; });
    });
})();
</script>
@endsection
