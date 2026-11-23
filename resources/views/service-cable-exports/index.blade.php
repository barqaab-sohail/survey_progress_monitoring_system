@extends('layouts.app')
@section('title', 'Feeder Service Cable Excel Export')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/ht-updates.css').'?v='.filemtime(public_path('css/ht-updates.css')) }}">
@endpush
@section('content')
<div class="ht-update">
<div class="page-head"><div><h1>Feeder service cable export</h1><p>Export all saved consumer service cables using the LT Network GIS Mapping workbook.</p></div><a class="btn btn-light" href="{{ route('field-surveys.index') }}">LT surveys</a></div>
<section class="card"><h2>Select a feeder</h2><p>Plan to survey five distribution transformers per feeder. This is a planning target: export the available records immediately, whether two, three, five or any other number of transformers have been surveyed.</p>
<form method="GET" action="{{ route('service-cable-exports.index') }}" class="ht-feeder-picker"><label for="cable-export-feeder">Feeder<select name="feeder_id" id="cable-export-feeder" required><option value="">Choose a feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" @selected($selected?->id === $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label><button class="btn btn-primary" type="submit">Load saved records</button></form></section>
@if($selected)
@php($transformers = $records->pluck('transformer_key')->unique()->count())
<section class="card"><h2>{{ $selected->feeder_code }} | {{ $selected->feeder_name }}</h2><p><strong>{{ $records->count() }} saved service cable records</strong> across <strong>{{ $transformers }} transformers</strong> (planning target: 5). Draft and submitted records are included.</p>
<p>{{ $records->whereNotNull('length_m')->count() }} records have an available mapped-route length, recorded measurement or coordinate estimate. {{ $records->whereNull('length_m')->count() }} have no available length and will have blank length cells.</p>
<p>Check the transformers below to choose what to export. Every saved cable and route point for the checked transformers is included; unchecked transformers are excluded from every sheet.</p></section>
<section class="card"><h2>Available transformer records</h2>
<form method="GET" action="{{ route('service-cable-exports.download', $selected) }}" id="service-cable-export-selection">
<p>Check one or more transformers, or use Select all to export this feeder's complete saved cable records.</p>
<div class="cable-export-selection-tools"><label class="cable-export-check"><input type="checkbox" id="cable-export-select-all" @disabled($records->isEmpty())> Select all transformers</label><span id="cable-export-selection-count" role="status" aria-live="polite">0 transformers selected</span></div>
<div class="ht-review-table-wrap"><table class="table cable-export-table"><thead><tr><th scope="col">Export</th><th scope="col">Transformer</th><th scope="col">Capacity (kVA)</th><th scope="col">Saved cables</th><th scope="col">With length</th><th scope="col">Available length (m)</th></tr></thead><tbody>
@forelse($records->groupBy('transformer_key') as $key => $cables)
@php($ratings = $cables->pluck('rating')->filter(fn ($rating) => is_numeric($rating) && (float) $rating > 0)->map(fn ($rating) => (float) $rating)->unique()->values())
<tr><td><label class="cable-export-check"><input type="checkbox" name="transformers[]" value="{{ $key }}" aria-label="Export transformer {{ $cables->first()['transformer'] }}" @checked(in_array($key, (array) old('transformers', []), true))></label></td><td>{{ $cables->first()['transformer'] }}</td><td>{{ $ratings->isEmpty() ? 'Not recorded' : $ratings->map(fn ($rating) => rtrim(rtrim(number_format($rating, 3, '.', ''), '0'), '.'))->implode(' / ') }}@if($ratings->count() > 1)<small>Conflicting recorded capacities</small>@endif</td><td>{{ $cables->count() }}</td><td>{{ $cables->whereNotNull('length_m')->count() }}</td><td>{{ $cables->whereNotNull('length_m')->isNotEmpty() ? number_format($cables->sum('length_m'), 3) : 'Not recorded' }}</td></tr>
@empty<tr><td colspan="6">No saved service cable records for this feeder yet. No records from other feeders will be included.</td></tr>@endforelse
</tbody></table></div>
<div class="ht-submit"><button type="submit" class="btn btn-primary" id="cable-export-download" @disabled($records->isEmpty())>Download service cable Excel</button><p>Only checked transformers will be exported. Coordinate estimates are included and labeled in the workbook; no consumer extrapolation is added.</p></div>
</form></section>
<script src="{{ asset('js/service-cable-export-selection.js').'?v='.filemtime(public_path('js/service-cable-export-selection.js')) }}" defer></script>
@endif
<section class="card"><h2>Record the actual cable route</h2><p>Start at the supplying LT pole, record intermediate GPS points at every bend and street turn, then finish at the consumer meter. Save the points in route order. The cable length is calculated along all recorded segments. A measured length can also be recorded with evidence; available pole-to-meter coordinate estimates are also exported with their source clearly labeled.</p></section>
</div>
@endsection
