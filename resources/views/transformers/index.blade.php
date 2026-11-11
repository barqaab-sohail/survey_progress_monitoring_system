@extends('layouts.app')
@section('title','Transformer GIS Data')
@section('content')
<div class="page-head">
    <div><h1>Transformer GIS Data</h1><p>Confirmed and provisional transformer points imported from the client KMZ and linked to the Excel feeder master.</p></div>
    @if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<a class="btn btn-primary" href="{{ route('feeder-kmz.index', ['feeder_id' => request('feeder_id')]) }}">Feeder HT + approved LT</a>@endif
    @can('Create:TransformerKmzImport')
        <a class="btn btn-primary" href="{{ url('/admin/transformer-kmz-imports') }}">Manage KMZ imports</a>
    @endcan
</div>

<div class="grid transformer-summary-grid">
    <div class="card kpi"><span class="kpi-accent"></span><span class="kpi-label">Transformer points</span><div class="kpi-value">{{ number_format($summary['transformers']) }}</div></div>
    <div class="card kpi accent-green"><span class="kpi-accent"></span><span class="kpi-label">Linked feeders</span><div class="kpi-value">{{ number_format($summary['feeders']) }}</div></div>
    <div class="card kpi accent-amber"><span class="kpi-accent"></span><span class="kpi-label">Known capacity (unknown ratings excluded)</span><div class="kpi-value">{{ number_format($summary['capacity_kva'], 0) }} <small>kVA</small></div></div>
</div>

<p class="alert alert-warning">{{ $summary['pending'] }} transformer reference(s) require client code confirmation or field capacity verification.</p>
<form class="card transformer-filters" method="GET">
    <div class="form-grid transformer-filter-grid">
        <div class="field"><label for="q">Search</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Transformer code, GPS waypoint, location..."></div>
        <div class="field"><label for="feeder_id">Linked feeder</label><select id="feeder_id" name="feeder_id"><option value="">All available feeders</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" @selected((string) request('feeder_id') === (string) $feeder->id)>{{ $feeder->feeder_code }} — {{ $feeder->feeder_name }}</option>@endforeach</select></div>
        <div class="field"><label for="capacity">Capacity</label><select id="capacity" name="capacity"><option value="">All kVA ratings</option>@foreach($capacities as $capacity)<option value="{{ $capacity }}" @selected((string) request('capacity') === (string) $capacity)>{{ number_format((float) $capacity, 0) }} kVA</option>@endforeach</select></div>
        <div class="field"><label>Verification</label><select name="verification"><option value="">All records</option>@foreach(['pending_client'=>'Client code confirmation pending','pending_field'=>'Capacity verification pending','confirmed'=>'Confirmed'] as $key=>$label)<option value="{{ $key }}" @selected(request('verification')===$key)>{{ $label }}</option>@endforeach</select></div><div class="actions transformer-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-light" href="{{ route('transformers.index') }}">Clear</a></div>
    </div>
</form>

<div class="card table-wrap desktop-table">
    <table class="table transformer-table">
        <thead><tr><th>Linked feeder</th><th>Transformer</th><th>Capacity</th><th>GPS / location</th><th>Technical details</th><th>Consumers</th><th></th></tr></thead>
        <tbody>
        @forelse($transformers as $transformer)
            @php($consumerTotal = $transformer->residential_total + $transformer->small_commercial + $transformer->large_commercial + $transformer->small_industries + $transformer->large_industries + $transformer->public_use + $transformer->agricultural + $transformer->street_lights)
            <tr>
                <td><strong>{{ $transformer->feeder->feeder_code }}</strong><small>{{ $transformer->feeder->feeder_name }}</small></td>
                <td><strong>{{ $transformer->transformer_code }}</strong><small>{{ $transformer->verification_label }}</small><small>Original code: {{ $transformer->source_transformer_code ?? $transformer->transformer_code }}</small><small>KMZ: {{ $transformer->source_feeder_name }}</small></td>
                <td><strong>{{ $transformer->capacity_kva === null ? 'Unknown capacity' : number_format((float) $transformer->capacity_kva, 0).' kVA' }}</strong></td>
                <td>@if($transformer->gps_waypoint_number)<strong>WP {{ $transformer->gps_waypoint_number }}</strong>@endif<small>{{ $transformer->equipment_location ?: 'Location not named' }}</small><a target="_blank" rel="noopener" href="{{ $transformer->map_url }}">Open map</a></td>
                <td><span class="status">{{ $transformer->equipment_status ?: 'Not stated' }}</span><small>{{ collect([$transformer->equipment_make, $transformer->equipment_mounting, $transformer->equipment_phase])->filter()->join(' · ') ?: '—' }}</small></td>
                <td><strong>{{ number_format($consumerTotal) }}</strong><small>recorded connections</small></td>
                <td><a class="btn btn-sm btn-light" href="{{ route('transformers.show', $transformer) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No transformer GIS records match these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mobile-cards">
@forelse($transformers as $transformer)
    <article class="mobile-record"><div class="page-head"><div><h3>{{ $transformer->transformer_code }}</h3><p>{{ $transformer->feeder->feeder_code }} · {{ $transformer->feeder->feeder_name }}</p></div><span class="status">{{ $transformer->capacity_kva === null ? 'Unknown capacity' : number_format((float) $transformer->capacity_kva, 0).' kVA' }}</span></div><dl><div><dt>GPS waypoint</dt><dd>{{ $transformer->gps_waypoint_number ?: '—' }}</dd></div><div><dt>Status</dt><dd>{{ $transformer->equipment_status ?: '—' }}</dd></div><div><dt>Location</dt><dd>{{ $transformer->equipment_location ?: '—' }}</dd></div><div><dt>Mounting</dt><dd>{{ $transformer->equipment_mounting ?: '—' }}</dd></div></dl><div class="actions"><a class="btn btn-sm btn-primary" href="{{ route('transformers.show', $transformer) }}">Full details</a><a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="{{ $transformer->map_url }}">Map</a></div></article>
@empty
    <div class="card empty">No transformer GIS records match these filters.</div>
@endforelse
</div>

{{ $transformers->links() }}
@endsection
