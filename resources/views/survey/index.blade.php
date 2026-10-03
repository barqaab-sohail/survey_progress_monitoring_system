@extends('layouts.app')
@section('title','My Survey Entries')
@section('content')
<div class="page-head"><div><h1>My Survey Entries</h1><p>Your submitted, verified, and returned field progress.</p></div><a class="btn btn-primary" href="{{ route('survey.create') }}">Add daily survey</a></div>
@forelse($entries as $entry)<article class="card"><div class="page-head"><div><h3 style="margin:0">{{ $entry->entry_date->format('d M Y') }}</h3><p>{{ $entry->team->name }} · Submitted {{ $entry->submitted_at->diffForHumans() }}</p></div><span class="status status-{{ $entry->status->value }}">{{ strtoupper(str_replace('_',' ',$entry->status->value)) }}</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>Quantity</th><th>Status</th><th>Drive data</th><th>Remarks</th></tr></thead><tbody>@foreach($entry->items as $item)<tr><td>{{ $item->feeder->feeder_code }}</td><td>{{ $item->transformers_surveyed }}</td><td><span class="status status-{{ $item->status->value }}">{{ strtoupper($item->status->value) }}</span></td><td>@if($item->drive_url)<a target="_blank" rel="noopener" href="{{ $item->drive_url }}">Open data</a>@else—@endif</td><td>{{ $item->return_reason ?: $item->remarks ?: '—' }}</td></tr>@endforeach</tbody></table></div></article>@empty<div class="card empty">No survey entries yet.</div>@endforelse
{{ $entries->links() }}
@endsection
