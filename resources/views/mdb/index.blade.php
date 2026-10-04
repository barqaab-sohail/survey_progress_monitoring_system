@extends('layouts.app')
@section('title','MDB Creation History')
@section('content')
<div class="page-head"><div><h1>MDB Creation History</h1><p>MDB files created from verified survey work.</p></div><a class="btn btn-primary" href="{{ route('mdb.create') }}">Add daily MDB</a></div>
<div class="card"><p>Saved entries appear automatically in the third-party verification queue. Edit entries before review; use Returned MDB Entries to correct and resubmit any returned item.</p><a class="btn btn-light" href="{{ route('mdb.returned') }}">Returned MDB Entries</a></div>
@forelse($entries as $entry)
<article class="card">
    <div class="page-head"><div><h3 style="margin:0">{{ $entry->entry_date->format('d M Y') }}</h3><p>{{ $entry->enteredBy->name }}{{ $entry->team ? ' · '.$entry->team->name : '' }}</p></div><div class="kpi-value">{{ $entry->items->sum('mdb_files_created') }}</div></div>
    @if($entry->canBeEdited() && auth()->user()->can('update', $entry))<a class="btn btn-sm btn-light" href="{{ route('mdb.edit', $entry) }}">Edit entry</a>@else<span class="muted">Editing locked after review</span>@endif
    <div class="table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>MDB created</th><th>Status</th><th>Drive</th><th>Remarks / return reason</th></tr></thead><tbody>
    @foreach($entry->items as $item)
    <tr><td>{{ $item->feeder->feeder_code }}</td><td>{{ $item->mdb_files_created }}</td><td><span class="status status-{{ $item->status->value }}">{{ strtoupper($item->status->value) }}</span></td><td>@if($item->drive_url)<a target="_blank" rel="noopener" href="{{ $item->drive_url }}">Open folder</a>@else—@endif</td><td>{{ $item->return_reason ?: $item->remarks ?: '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</article>
@empty<div class="card empty">No MDB creation has been recorded.</div>@endforelse
{{ $entries->links() }}
@endsection
