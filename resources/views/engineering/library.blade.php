@extends('layouts.app')
@section('title', 'Engineering defaults library')
@section('content')
<div class="page-head"><div><h1>Engineering defaults library</h1><p>Reusable design settings for new project configurations. Each project retains its own editable copy and approval.</p></div><a class="btn btn-light" href="{{ route('engineering.index') }}">Project configurations</a></div>
@if($preset)
@php($evidence = $preset->evidence)
<section class="card"><p><strong>{{ $preset->name }} · revision {{ $preset->revision }}</strong></p><p>{{ count($evidence['sources'] ?? []) }} reference MDBs · {{ $evidence['consistent_parameter_count'] ?? 0 }} consistent parameters. Library changes apply to future configurations. Existing custom settings and approvals are retained.</p><p>Reference observations supply defaults, not project approval. Missing values stay blank. Confirm the CRS, frequency, LT voltage, equipment definitions and demand method in each project's Engineering Configuration.</p></section>
<form method="POST" action="{{ route('engineering.library.update') }}">@csrf @method('PUT')
<input type="hidden" name="revision" value="{{ old('revision', $preset->revision) }}">
<section class="card"><label>Library name<input name="name" value="{{ old('name', $preset->name) }}" maxlength="255" required></label>
<label>Load defaults for new configurations<select name="enabled" required><option value="1" @selected((string) old('enabled', (int) $preset->enabled) === '1')>Enabled</option><option value="0" @selected((string) old('enabled', (int) $preset->enabled) === '0')>Disabled</option></select></label>
<label>Default configuration (JSON)<textarea name="configuration_json" rows="28" required spellcheck="false">{{ old('configuration_json', json_encode($preset->configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea></label>
<p>Use <code>null</code> for unresolved values. Edit common electrical settings, construction, equipment references, transformer type mappings and load assumptions here. Per-network IDs, coordinates, measured loads and wiring do not belong in shared defaults. Preserve spaces in exact equipment reference names.</p>
<label>Reason for change<textarea name="reason" rows="3" minlength="5" maxlength="4000" required>{{ old('reason') }}</textarea></label><button class="btn btn-primary">Save library revision</button></section></form>
<section class="card"><h2>Original reference evidence</h2><p>This extraction remains available after editing the defaults.</p>
<details><summary>Reference files and SHA-256 hashes</summary><ul>@foreach($evidence['sources'] ?? [] as $source)<li>{{ $source['file'] }} <code>{{ $source['sha256'] }}</code></li>@endforeach</ul></details>
<details><summary>Conflicting observations ({{ count($evidence['conflicts'] ?? []) }})</summary>@foreach($evidence['conflicts'] ?? [] as $conflict)<p><strong>{{ $conflict['field'] }}</strong></p><pre>{{ json_encode($conflict['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@endforeach</details>
<details><summary>Missing or unresolved values ({{ count($evidence['missing'] ?? []) }})</summary><ul>@foreach($evidence['missing'] ?? [] as $item)<li><strong>{{ $item['field'] }}:</strong> {{ $item['reason'] }}</li>@endforeach</ul></details>
<details><summary>Equipment, component and source-role evidence</summary><pre>{{ json_encode(array_intersect_key($evidence, array_flip(['equipment_references','conditional_table_values','role_table_values','load_evidence','notes'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
<details><summary>Field-by-field parameter evidence</summary><pre>{{ json_encode($evidence['field_evidence'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></section>
@else
<section class="card"><p>No engineering defaults library has been installed.</p></section>
@endif
@endsection
@push('styles')<style>.card label{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}.card textarea{width:100%;box-sizing:border-box;font-family:monospace}.card pre{white-space:pre-wrap;overflow-wrap:anywhere}</style>@endpush
