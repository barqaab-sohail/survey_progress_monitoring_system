@extends('layouts.app')
@section('title','Processing History')
@section('content')
<div class="page-head"><div><h1>Processing History</h1><p>Your organization's recorded MDB analysis progress.</p></div><a class="btn btn-primary" href="{{ route('processing.entries.create') }}">Add today's progress</a></div>
@forelse($entries as $entry)<article class="card"><div class="page-head"><div><h3 style="margin:0">{{ $entry->entry_date->format('d M Y') }}</h3><p>Entered by {{ $entry->enteredBy->name }}</p></div><div class="kpi-value">{{ $entry->items->sum('mdb_processed') }}</div></div><div class="table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>Processed</th><th>Output</th><th>Remarks</th></tr></thead><tbody>@foreach($entry->items as $item)<tr><td>{{ $item->assignment->feeder->feeder_code }}</td><td>{{ $item->mdb_processed }}</td><td>@if($item->output_drive_url)<a target="_blank" rel="noopener" href="{{ $item->output_drive_url }}">Open output</a>@else—@endif</td><td>{{ $item->remarks ?: '—' }}</td></tr>@endforeach</tbody></table></div></article>@empty<div class="card empty">No processing progress recorded yet.</div>@endforelse
{{ $entries->links() }}
@endsection
