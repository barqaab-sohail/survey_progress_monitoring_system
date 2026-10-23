@extends('layouts.app')
@section('title', 'Correct mobile field survey')
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
<style>
.field-correction-layout{display:grid;grid-template-columns:minmax(0,3fr) minmax(300px,2fr);gap:20px;align-items:start}
.field-correction-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}
.field-correction-fields input,.field-correction-fields select{width:100%;box-sizing:border-box}
.field-evidence{position:sticky;top:15px;max-height:85vh;overflow:auto}
.field-evidence img{display:block;max-width:100%;max-height:65vh;object-fit:contain;margin:12px auto}
@media(max-width:1000px){.field-correction-layout{grid-template-columns:1fr}.field-evidence{position:static;max-height:none;grid-row:1}}
</style>
<div class="page-head"><div><h1>Correct mobile field survey</h1><p>{{ $survey->transformer_code }} &middot; {{ $survey->feeder->feeder_name }} &middot; Server revision {{ $survey->revision }}</p></div><a class="btn btn-light" href="{{ route('field-surveys.show', $survey->client_uuid) }}">Cancel</a></div>
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
    @foreach($payload['rows'] ?? [] as $index=>$row)
    <section class="card" id="survey-row-{{ $index }}"><h2>Row {{ $index + 1 }} &middot; {{ $row['se'] ?? '-' }}</h2><div class="field-correction-fields">
        @include('field-surveys.edit-input', ['path'=>"rows.$index.group",'label'=>'Group identifier','type'=>'text'])
        @include('field-surveys.edit-input', ['path'=>"rows.$index.date",'label'=>'Date','type'=>'date'])
        @include('field-surveys.edit-input', ['path'=>"rows.$index.gps_waypoint",'label'=>'GPS waypoint','type'=>'text'])
        @foreach(['r'=>'R','y'=>'Y','b'=>'B','neutral'=>'Neutral'] as $phase=>$label)
            @include('field-surveys.edit-input', ['path'=>"rows.$index.conductor_$phase",'label'=>'Conductor '.$label,'type'=>'text'])
        @endforeach
        @foreach(['equipment_type'=>'Equipment type','pole_class'=>'Pole class','remarks'=>'Row remarks'] as $key=>$label)
            @include('field-surveys.edit-input', ['path'=>"rows.$index.$key",'label'=>$label,'type'=>'text'])
        @endforeach
        @include('field-surveys.edit-input', ['path'=>"rows.$index.pole_height_ft",'label'=>'Pole height (ft)','type'=>'number'])
        <label><input data-field="rows.{{ $index }}.intersection" type="checkbox" @checked(in_array(\App\Support\IntersectionFlag::normalize($row['intersection'] ?? false), [true,1], true))> Intersection</label>
    </div><h3>Consumer counts</h3><div class="field-correction-fields">
        @foreach($categories as $category)
            @if(is_array($row['consumer_phases'][$category] ?? null))
                @foreach(['R','Y','B'] as $phase)
                    @include('field-surveys.edit-input', ['path'=>"rows.$index.consumer_phases.$category.$phase",'label'=>strtoupper($category).' / '.$phase,'type'=>'number'])
                @endforeach
            @else
                @include('field-surveys.edit-input', ['path'=>"rows.$index.consumers.$category",'label'=>strtoupper($category),'type'=>'number'])
            @endif
        @endforeach
    </div></section>
    @endforeach
    @foreach($payload['solar'] ?? [] as $index=>$pv)
    <section class="card"><h2>Solar entry {{ $index + 1 }}</h2><div class="field-correction-fields">
        @foreach(['consumer_reference'=>'Consumer reference','meter_number'=>'Meter number','remarks'=>'Remarks'] as $key=>$label)
            @include('field-surveys.edit-input', ['path'=>"solar.$index.$key",'label'=>$label,'type'=>'text'])
        @endforeach
        @include('field-surveys.edit-input', ['path'=>"solar.$index.service_load_kw",'label'=>'PV service load (kW)','type'=>'number'])
        @include('field-surveys.edit-input', ['path'=>"solar.$index.installed_pv_kw",'label'=>'Installed PV (kW)','type'=>'number'])
    </div></section>
    @endforeach
    <section class="card">
        <label>Survey remarks<textarea data-field="remarks" rows="3">{{ $payload['remarks'] ?? '' }}</textarea></label>
        <label>Correction notes (optional)<textarea name="correction_reason" maxlength="2000" rows="2">{{ old('correction_reason') }}</textarea></label>
        <p>Save creates a new server revision and preserves the original photo links. The phone's synced copy remains read-only.</p>
        <button type="submit" class="btn btn-primary">Save corrections</button>
    </section>
</form>
<aside class="card field-evidence"><h2>Uploaded evidence</h2>
    <label>Select image or sketch<select id="field-evidence-select">
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
