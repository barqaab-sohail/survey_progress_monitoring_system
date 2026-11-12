@extends('layouts.app')
@section('title', 'HT Network Update - 11 kV feeder')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/ht-updates.css').'?v='.filemtime(public_path('css/ht-updates.css')) }}">
@endpush
@section('content')
<div class="ht-update">
<div class="page-head"><div><h1>HT Network Update - 11 kV feeder</h1><p>Add a missing HT transformer or extend the primary HT feeder. Independent HT approval is required before LT survey entry.</p></div><a class="btn btn-light" href="{{ route('ht-updates.index') }}">HT updates</a></div>
@if($errors->any())<div class="alert alert-danger"><strong>Correct the HT update:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
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
<section class="card"><h2>HT feeder: {{ $selected->feeder_code }} - {{ $selected->feeder_name }}</h2><p>Required: team, transformer code, update type, upstream HT connection and route waypoint names. Coordinates can be resolved from GPX during review; approval requires every coordinate and a confirmed physical connection.</p>
<div class="ht-fields">
<label>Responsible survey team (required)<select name="survey_team_id" required><option value="">Choose team</option>@foreach($teams->where('project_id', $selected->project_id) as $team)<option value="{{ $team->id }}" @selected((string) old('survey_team_id', $update?->survey_team_id ?? ($teams->where('project_id', $selected->project_id)->count() === 1 ? $teams->where('project_id', $selected->project_id)->first()->id : null)) === (string) $team->id)>{{ $team->name }}</option>@endforeach</select></label>
<label>HT transformer code (required)<input name="transformer_code" value="{{ old('transformer_code', $p['transformer_code'] ?? '') }}" maxlength="100" required @readonly($update)></label>
<label>HT update type (required)<select name="kind" id="ht-kind" required>@foreach(['missing_transformer'=>'Missing transformer on existing HT', 'ht_extension'=>'HT feeder extension with transformer'] as $key=>$label)<option value="{{ $key }}" @selected(old('kind', $p['kind'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Connect to existing approved HT feature (required)<select name="upstream_feature_id" required><option value="">Choose upstream HT connection</option>@foreach($network['features'] as $feature)<option value="{{ $feature['id'] }}" @selected(old('upstream_feature_id', $p['upstream_feature_id'] ?? '') === $feature['id'])>{{ $feature['label'] }} [{{ $feature['id'] }}]</option>@endforeach</select></label>
</div></section>
<section class="card"><h2>HT route: connection to transformer</h2><p>First row: existing HT connection. Last row: transformer. For an extension, add intermediate HT poles in route order. Pole details, conductors and coordinates are optional at submission. Use approved warehouse conductors when known.</p>
<div id="ht-nodes">@foreach(old('nodes', $p['nodes'] ?? [['waypoint'=>''], ['waypoint'=>'']]) as $node)
@include('ht-updates.node', ['node'=>$node, 'i'=>$loop->index, 'endpoint'=>$loop->first || $loop->last, 'nodeLabel'=>$loop->first ? 'Existing HT connection' : ($loop->last ? 'HT transformer' : 'Intermediate HT pole '.$loop->index)])
@endforeach</div><button type="button" id="ht-add-node" class="btn btn-light" @if(old('kind', $p['kind'] ?? 'missing_transformer') !== 'ht_extension') hidden @endif>Add intermediate HT pole</button></section>
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
</details></section><div class="ht-submit"><button class="btn btn-primary">Submit HT Network Update for verification</button><p>Saved as pending HT data for independent review.</p></div>
</form>
<template id="ht-node-template">@include('ht-updates.node', ['node'=>[], 'i'=>'__INDEX__'])</template>
<script src="{{ asset('js/ht-update-form.js').'?v='.filemtime(public_path('js/ht-update-form.js')) }}" defer></script>
@elseif($selected)<section class="card"><p>No approved HT connection is available for this feeder. Import or restore its original HT KMZ before submitting an HT update.</p></section>@endif
</div>
@endsection
