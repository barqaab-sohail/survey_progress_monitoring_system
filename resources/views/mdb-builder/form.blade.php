@extends('layouts.app')
@section('title', $project->exists ? 'Edit transformer MDB survey' : 'Enter paper transformer survey')
@section('content')
@php
    $initial = [
        'feeder_id' => old('feeder_id', $project->feeder_id), 'transformer_code' => old('transformer_code', $project->transformer_code),
        'survey_date' => old('survey_date', $project->survey_date->toDateString()), 'revision' => old('revision', $project->revision),
        'source_field_survey_id' => $project->source_field_survey_id, 'header' => old('header', $project->header), 'rows' => old('rows', $project->rows),
        'solar' => old('solar', $project->solar), 'remarks' => old('remarks', $project->remarks), 'export_settings' => old('export_settings', $project->export_settings),
    ];
    $references = $feeders->mapWithKeys(fn ($feeder) => [$feeder->id => [
        'substation' => $feeder->gridStation?->name, 'division' => $feeder->division?->name, 'sub_division' => $feeder->subDivision?->name,
        'sub_division_code' => $feeder->subDivision?->code, 'transformers' => $feeder->transformers->map(fn ($transformer) => $transformer->only(['transformer_code','gps_waypoint_number','capacity_kva','equipment_make','equipment_location','latitude','longitude'])),
    ]]);
@endphp
<div class="page-head"><div><h1>{{ $project->exists ? $project->transformer_code : 'Enter paper transformer survey' }}</h1><p>One workspace per transformer. Transcribe the PDF and enter each line as consecutive S/E rows.</p></div><a class="btn btn-light" href="{{ route('mdb-builder.index') }}">All workspaces</a></div>
<noscript><div class="alert alert-error">JavaScript is required to enter survey rows and save this form.</div></noscript>
<form id="mdb-form" method="POST" enctype="multipart/form-data" action="{{ $project->exists ? route('mdb-builder.update', $project) : route('mdb-builder.store') }}">
@csrf @if($project->exists) @method('PUT') @endif
<input type="hidden" name="payload" id="mdb-payload">
<section class="card"><h2>1. Survey sheet and GPX</h2><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(250px,1fr))">
<label>Survey PDF <input type="file" name="pdf" accept=".pdf,application/pdf" id="mdb-pdf"><small>Optional reference, up to 50 MB. Enter the handwritten values below.</small></label>
<label>GPX waypoints <input type="file" name="gpx" accept=".gpx" id="mdb-gpx"><small>Up to 10 MB. Save after uploading to see waypoint matches. A new file replaces the previous GPX.</small></label>
</div>
@if($project->exists)<div class="actions" style="margin-top:12px">@if($project->pdf_path)<a class="btn btn-light" href="{{ route('mdb-builder.source', [$project,'pdf']) }}" target="_blank" rel="noopener">Open saved PDF</a>@endif @if($project->gpx_path)<a class="btn btn-light" href="{{ route('mdb-builder.source', [$project,'gpx']) }}">Download saved GPX</a><span>{{ count($project->gpx_waypoints ?? []) }} named waypoints loaded</span>@endif</div>@endif
<details id="pdf-reference" style="margin-top:14px"><summary>View PDF reference</summary><iframe id="pdf-viewer" title="Survey PDF reference" @if($project->exists && $project->pdf_path) src="{{ route('mdb-builder.source', [$project,'pdf']) }}" @endif style="width:100%;height:640px;border:1px solid var(--line);margin-top:12px"></iframe></details>
</section>
<section class="card"><h2>2. Transformer and administration</h2><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
<label>Feeder<select id="mdb-feeder" required><option value="">Select feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}">{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label>
<label>Use existing transformer reference<select id="mdb-transformer"><option value="">Enter manually</option></select><small>Choosing a reference fills its code, capacity, make, and confirmed GIS position.</small></label>
<label>Transformer code<input id="mdb-code" maxlength="100" required></label><label>Survey date<input id="mdb-date" type="date" max="{{ today()->toDateString() }}" required></label>
@foreach(['substation'=>'Substation','division'=>'Division','sub_division'=>'Sub Division','sub_division_code'=>'Sub Division code','capacity_kva'=>'Capacity (kVA)','transformer_make'=>'Transformer make','inspectors'=>'Inspectors','location'=>'Location'] as $key=>$label)
<label>{{ $label }}<input data-header="{{ $key }}" @if($key==='capacity_kva') type="number" min="0.01" step="any" @else maxlength="{{ $key==='location' ? 500 : 200 }}" @endif></label>
@endforeach
<label>Mounting<select data-header="mounting"><option value="">Not recorded</option>@foreach(['S.Pole','D.Pole','Pad'] as $value)<option>{{ $value }}</option>@endforeach</select></label><label>Duty<select data-header="duty"><option value="">Not recorded</option><option>General Duty</option><option>Dedicated</option></select></label>
</div></section>
<section class="card"><div class="section-title"><h2>3. Paper survey rows</h2><button class="btn btn-light" type="button" id="add-pair">Add S/E pair</button></div>
<p>Each S/E pair becomes a section. Enter phase and conductors on S, consumers on E. Repeat waypoint identifiers to form branches. Enter ditto marks as their actual values. Coordinates may stay blank when a GPX waypoint matches.</p>
<div style="overflow-x:auto"><table class="table mdb-paper-table"><thead><tr><th>Row</th><th>S/E</th><th>Group</th><th>Date</th><th>GPS WP</th><th>Phase</th><th>R</th><th>Y</th><th>B</th><th>Neutral</th><th>Type</th><th>Pole class</th><th>Height (ft)</th>@foreach(['RS','RL','SC','LC','SI','LI','PB','AG','ST'] as $key)<th>{{ $key }}</th>@endforeach<th>Intersection</th><th>Latitude</th><th>Longitude</th><th>GPX link</th><th>Remarks</th><th></th></tr></thead><tbody id="mdb-rows"></tbody></table></div>
<small>A = ANT, W = WASP, GN = GNAT. Other conductor IDs remain as entered. A blank consumer cell stays unrecorded until you explicitly choose the zero-count convention below.</small>
</section>
<section class="card"><div class="section-title"><h2>4. Solar / net metering</h2><button class="btn btn-light" type="button" id="add-solar">Add solar entry</button></div><p>These entries are preserved in the MDB survey tables. Assign generator locations and electrical models in SynerGEE.</p><div id="mdb-solar"></div></section>
<section class="card"><h2>5. Transformer connection and engineering settings</h2>
<p>Identify all paper waypoints that refer to the transformer connection, for example <strong>878,879</strong>. They connect to the transformer rather than creating separate distribution nodes. Confirm its actual coordinates.</p>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
<label>Transformer waypoint aliases<input data-setting="transformer_waypoints" maxlength="200" placeholder="878,879"></label>
<label>Use a GPX transformer position<select id="root-gpx"><option value="">Choose a loaded waypoint</option></select></label>
<label>Transformer latitude<input data-setting="transformer_latitude" type="number" min="0" max="84" step="any"></label><label>Transformer longitude<input data-setting="transformer_longitude" type="number" min="-180" max="180" step="any"></label>
<label>UTM zone (north)<input data-setting="utm_zone" type="number" min="1" max="60" required><small id="utm-hint">Choose the zone for the survey location. Noor Pur GPX coordinates use 42N; the supplied MDB's 43N header is inconsistent with its coordinates.</small></label>
<label>Frequency (Hz)<select data-setting="frequency"><option value="50">50</option><option value="60">60</option></select></label>
<label>Supply voltage (kV)<input data-setting="nominal_kv" type="number" min="0.001" max="500" step="any" required></label>
<label>Transformer library type<input data-setting="transformer_type" maxlength="200" placeholder="Capacity KVA, e.g. 100 KVA"><small>Must exist in the target SynerGEE library.</small></label>
<label>Line configuration library ID<input data-setting="configuration_id" maxlength="200"></label>
<label>Phase spacing (cm)<input data-setting="phase_spacing_cm" type="number" min="0.001" step="any" required></label>
<label>Neutral spacing (cm)<input data-setting="neutral_spacing_cm" type="number" min="0.001" step="any" required></label>
<label>Conductor height (m)<input data-setting="conductor_height_m" type="number" min="0.001" step="any" required></label>
</div>
<h3>Connected load per consumer (kVA)</h3><p>Enter the approved kVA per consumer for categories used in this survey. Loads are shared equally across the section's connected phases. Enter 0 only when intentionally modelling zero connected load.</p>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(100px,1fr))">@foreach(['rs','rl','sc','lc','si','li','pb','ag','st'] as $category)<label>{{ strtoupper($category) }}<input data-rate="{{ $category }}" type="number" min="0" step="any"></label>@endforeach</div>
<label class="mdb-check"><input type="checkbox" data-setting="blank_consumers_zero">Treat all blank E row consumer cells as zero for MDB generation</label>
<label class="mdb-check"><input type="checkbox" data-setting="engineering_reviewed">I have reviewed the transformer connection, library IDs, load allocation, and engineering settings</label>
<small>Spacing and network defaults start from the sample MDB. Check them for this transformer. The full paper observations remain in SurveyHeader, SurveyRows, and SurveySolar.</small>
</section>
<section class="card"><label>Overall remarks<textarea id="mdb-remarks" maxlength="2000" rows="3"></textarea></label><div class="actions" style="margin-top:16px"><button class="btn btn-primary" id="save-mdb">Save survey and files</button>@if($project->exists)<a class="btn btn-light" id="preview-mdb" href="{{ route('mdb-builder.preview', $project) }}">Review saved network / Create MDB</a>@endif<span id="mdb-save-state" role="status">Changes are saved when you choose Save.</span></div></section>
</form>
<template id="mdb-row-template"><tr>
<td data-number></td><td><select data-row="se" aria-label="S/E"><option>S</option><option>E</option></select></td>
<td><input data-row="group" maxlength="20" aria-label="Group"></td><td><input data-row="date" type="date" max="{{ today()->toDateString() }}" aria-label="Observation date"></td>
<td><input data-row="gps_waypoint" maxlength="50" list="gpx-names" aria-label="GPS waypoint"></td><td><input data-row="phase" maxlength="30" aria-label="Phase" placeholder="RYB"></td>
@foreach(['r','y','b','neutral'] as $phase)<td><input data-row="conductor_{{ $phase }}" maxlength="100" aria-label="Conductor {{ $phase }}"></td>@endforeach
<td><input data-row="equipment_type" maxlength="50" aria-label="Equipment type"></td><td><input data-row="pole_class" maxlength="50" aria-label="Pole class"></td><td><input data-row="pole_height_ft" type="number" min="0" step="any" aria-label="Pole height"></td>
@foreach(['rs','rl','sc','lc','si','li','pb','ag','st'] as $category)<td><input data-consumer="{{ $category }}" type="number" min="0" max="1000000" step="1" aria-label="Consumers {{ strtoupper($category) }}"></td>@endforeach
<td><input data-row="intersection" type="checkbox" aria-label="Intersection"></td><td><input data-row="latitude" type="number" min="-90" max="90" step="any" aria-label="Latitude"></td><td><input data-row="longitude" type="number" min="-180" max="180" step="any" aria-label="Longitude"></td><td data-gpx-link></td><td><input data-row="remarks" maxlength="1000" aria-label="Row remarks"></td><td><button type="button" class="btn btn-sm btn-light" data-delete-pair>Remove pair</button></td>
</tr></template>
<template id="mdb-solar-template"><div class="grid mdb-solar-entry" style="grid-template-columns:2fr 1fr 2fr auto;align-items:end;margin-bottom:12px"><label>Consumer reference<input data-solar="consumer_reference" maxlength="100"></label><label>PV capacity (kW)<input data-solar="installed_pv_kw" type="number" min="0" step="any"></label><label>Remarks<input data-solar="remarks" maxlength="1000"></label><button type="button" class="btn btn-light" data-delete-solar>Remove</button></div></template>
<datalist id="gpx-names"></datalist>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-builder.css') }}">@endpush
@push('scripts')
<script>window.mdbEditorData = {{ \Illuminate\Support\Js::from(['initial'=>$initial,'references'=>$references,'waypoints'=>$project->gpx_waypoints ?? []]) }};</script>
<script src="{{ asset('js/mdb-builder.js') }}?v=intersection"></script>
@endpush
