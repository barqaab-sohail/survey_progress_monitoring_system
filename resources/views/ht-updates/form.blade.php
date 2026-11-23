@extends('layouts.app')
@section('title', 'HT Network Update - 11 kV feeder')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/ht-updates.css').'?v='.filemtime(public_path('css/ht-updates.css')) }}">
@endpush
@section('content')
<div class="ht-update">
<div class="page-head"><div><h1>HT Network Update - 11 kV feeder</h1><p>Choose an imported feeder, add a transformer with or without new poles, then download the updated feeder KMZ after HT approval.</p></div><a class="btn btn-light" href="{{ route('ht-updates.index') }}">HT updates</a></div>
@if($errors->any())<div class="alert alert-danger"><strong>Correct the HT update:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($selectionMessage)<div class="alert alert-warning" role="status">{{ $selectionMessage }}</div>@endif
<p>Only feeders with a completed KMZ import and an available, unchanged original file are listed.</p>
@if($feeders->isEmpty())<section class="card"><p>No imported KMZ is available for your accessible feeders. Import or restore the original feeder KMZ before creating an HT update.</p></section>@endif
@if(!$update)
<form method="GET" action="{{ route('ht-updates.create') }}" class="card ht-feeder-picker"><label>HT feeder (required)<select name="feeder_id" required><option value="">Choose feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" @selected($selected?->id === $feeder->id)>{{ $feeder->feeder_code }} - {{ $feeder->feeder_name }}</option>@endforeach</select></label><button class="btn btn-light">Load HT feeder connections</button></form>
@endif
@if($selected && count($network['features']))
@php($p = $update?->payload ?? [])
<form method="POST" enctype="multipart/form-data" action="{{ $update ? route('ht-updates.update', $update) : route('ht-updates.store') }}">
@csrf @if($update) @method('PUT') @endif
<input type="hidden" name="client_uuid" value="{{ old('client_uuid', $update?->client_uuid ?? (string) Illuminate\Support\Str::uuid()) }}">
<input type="hidden" name="base_revision" value="{{ old('base_revision', $update?->revision ?? 0) }}">
<input type="hidden" name="feeder_id" value="{{ $selected->id }}">
<input type="hidden" name="baseline_sha256" value="{{ $network['baseline_sha256'] }}">
<section class="card"><h2>HT feeder: {{ $selected->feeder_code }} - {{ $selected->feeder_name }}</h2><p>Required: team, transformer code, update type, upstream HT connection and route waypoint names. Latitude and longitude are required for every route row before submission. Copy the existing connection coordinates from the KMZ and enter the actual coordinates for the transformer and each new pole.</p>
<fieldset class="ht-kind-options"><legend>1. What do you want to add?</legend>
@foreach(['missing_transformer'=>['Transformer only — no new poles', 'Place a transformer on the existing HT network. No new poles or HT spans will be added to the KMZ.'], 'ht_extension'=>['New poles, then transformer', 'Extend from an existing HT connection, add poles in route order, and place the transformer at the end.']] as $key=>$option)
<label class="ht-kind-card"><input type="radio" name="kind" value="{{ $key }}" @checked(old('kind', $p['kind'] ?? 'missing_transformer') === $key) required><span><strong>{{ $option[0] }}</strong><small>{{ $option[1] }}</small></span></label>
@endforeach
</fieldset>
<div class="ht-fields">
<label>Responsible survey team (required)<select name="survey_team_id" required><option value="">Choose team</option>@foreach($teams->where('project_id', $selected->project_id) as $team)<option value="{{ $team->id }}" @selected((string) old('survey_team_id', $update?->survey_team_id ?? ($teams->where('project_id', $selected->project_id)->count() === 1 ? $teams->where('project_id', $selected->project_id)->first()->id : null)) === (string) $team->id)>{{ $team->name }}</option>@endforeach</select></label>
<label>HT transformer code (required)<input name="transformer_code" value="{{ old('transformer_code', $p['transformer_code'] ?? '') }}" maxlength="100" required @readonly($update)></label>
<label>Connect to existing approved HT feature (required)<select name="upstream_feature_id" id="ht-upstream" required><option value="">Choose upstream HT connection</option>@foreach($network['features'] as $feature)<option value="{{ $feature['id'] }}" data-coordinates="{{ json_encode($feature['coordinates']) }}" data-type="{{ $feature['type'] }}" @selected(old('upstream_feature_id', $p['upstream_feature_id'] ?? '') === $feature['id'])>{{ $feature['label'] }} [{{ $feature['id'] }}]</option>@endforeach</select></label>
</div></section>
<section class="card"><h2>2. Connection, poles and transformer</h2>
<p id="ht-route-help">First row: existing HT connection. Last row: new transformer. Add intermediate poles only when extending HT.</p>
<p id="ht-route-summary" class="ht-route-summary" role="status" aria-live="polite"></p>
<button type="button" id="ht-use-connection" class="btn btn-light">Fill existing connection coordinates from KMZ</button>
<p id="ht-connection-help">For a line connection this uses its first endpoint. Adjust the coordinates to the actual connection on that line.</p>
<div id="ht-nodes">@foreach(old('nodes', $p['nodes'] ?? [['waypoint'=>''], ['waypoint'=>'']]) as $node)
@include('ht-updates.node', ['node'=>$node, 'i'=>$loop->index, 'endpoint'=>$loop->first || $loop->last, 'nodeLabel'=>$loop->first ? 'Existing HT connection' : ($loop->last ? 'HT transformer' : 'Intermediate HT pole '.$loop->index)])
@endforeach</div><div id="ht-pole-tools" @if(old('kind', $p['kind'] ?? 'missing_transformer') !== 'ht_extension') hidden @endif><label>Number of poles to add<input id="ht-pole-count" type="number" min="1" max="198" value="1"></label><button type="button" id="ht-add-node" class="btn btn-light">Add poles before transformer</button><p>Enter each pole waypoint in order from the existing HT connection toward the transformer. The final transformer row stays last.</p></div></section>
<section class="card"><details><summary>Optional HT transformer details and evidence</summary>
<div class="ht-fields ht-optional-fields">
<label>Capacity (kVA)<select name="capacity_kva"><option value="">Unknown - field verification required</option>@foreach(App\Services\Mdb\ApprovedWarehouse::capacities() as $capacity)<option value="{{ $capacity }}" @selected((string) old('capacity_kva', $p['capacity_kva'] ?? '') === (string) $capacity)>{{ $capacity }} KVA</option>@endforeach</select></label>
@foreach(['make'=>'Make','location'=>'Location description','observed_on'=>'Observation date'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" type="{{ $key === 'observed_on' ? 'date' : 'text' }}" value="{{ old($key, $p[$key] ?? '') }}"></label>@endforeach
<label>Mounting<select name="mounting"><option value="">Unknown</option>@foreach(['S.Pole','D.Pole','Pad'] as $mounting)<option @selected(old('mounting', $p['mounting'] ?? '') === $mounting)>{{ $mounting }}</option>@endforeach</select></label>
<label>Transformer phases<select name="phase"><option value="">Unknown</option>@foreach(['R','Y','B','R-Y','Y-B','R-B','R-Y-B'] as $phase)<option @selected(old('phase', $p['phase'] ?? '') === $phase)>{{ $phase }}</option>@endforeach</select></label>
<label class="ht-wide">Remarks<textarea name="remarks" maxlength="4000">{{ old('remarks', $p['remarks'] ?? '') }}</textarea></label>
<label class="ht-wide">HT photographs (JPEG/PNG, up to 1 MB each; three total)<input type="file" name="photos[]" accept="image/jpeg,image/png" multiple></label>
</div>
@if($update)<p>{{ count($p['photos'] ?? []) }} existing photographs will be retained.</p>@endif
</details></section><section class="card"><h2>3. Review and download updated KMZ</h2><p>Submit this addition for approval. Super Admins may approve their own updates; other submitters need a different authorized reviewer. After approval, download the updated feeder KMZ from the update details page. It includes the original feeder and all verified HT additions, preserving the original file.</p></section><div class="ht-submit"><button class="btn btn-primary">Submit HT Network Update for verification</button><p>Saved as pending HT data for review. Super Admins can approve their own updates.</p></div>
</form>
<template id="ht-node-template">@include('ht-updates.node', ['node'=>[], 'i'=>'__INDEX__'])</template>
<script src="{{ asset('js/ht-update-form.js').'?v='.filemtime(public_path('js/ht-update-form.js')) }}" defer></script>
@elseif($selected)<section class="card"><p>No approved HT connection is available for this feeder. Import or restore its original HT KMZ before submitting an HT update.</p></section>@endif
</div>
@endsection
