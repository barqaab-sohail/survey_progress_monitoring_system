@extends('layouts.app')
@section('title','MDB Creation History')
@section('content')
<div class="page-head"><div><h1>MDB Creation History</h1><p>Recorded production from verified survey work.</p></div><a class="btn btn-primary" href="{{ route('mdb.create') }}">Add daily MDB</a></div>
@forelse($entries as $entry)<article class="card"><div class="page-head"><div><h3 style="margin:0">{{ $entry->entry_date->format('d M Y') }}</h3><p>{{ $entry->enteredBy->name }}{{ $entry->team ? ' · '.$entry->team->name : '' }}</p></div><div class="kpi-value">{{ $entry->items->sum('mdb_files_created') }}</div></div><div class="table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>MDB created</th><th>Drive</th><th>Remarks</th></tr></thead><tbody>@foreach($entry->items as $item)<tr><td>{{ $item->feeder->feeder_code }}</td><td>{{ $item->mdb_files_created }}</td><td>@if($item->drive_url)<a target="_blank" rel="noopener" href="{{ $item->drive_url }}">Open folder</a>@else—@endif</td><td>{{ $item->remarks ?: '—' }}</td></tr>@endforeach</tbody></table></div></article>@empty<div class="card empty">No MDB creation has been recorded.</div>@endforelse
{{ $entries->links() }}
@endsection
