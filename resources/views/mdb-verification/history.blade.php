@extends('layouts.app')
@section('title','MDB Review History')
@section('content')
<div class="page-head"><div><h1>MDB Review History</h1><p>Verified, returned, and resubmitted MDB records with their review trail.</p></div><a class="btn btn-light" href="{{ route('mdb-verification.index') }}">Pending MDB verification</a></div>
<div class="card table-wrap"><table class="table"><thead><tr><th>Time</th><th>Feeder</th><th>Entry date</th><th>Action</th><th>Quantity</th><th>By</th><th>Reason / notes</th></tr></thead><tbody>
@forelse($histories as $history)
<tr><td>{{ $history->acted_at->format('d M Y h:i A') }}</td><td>{{ $history->item->feeder->feeder_code }}</td><td>{{ $history->item->entry->entry_date->format('d M Y') }}</td><td>{{ strtoupper($history->action) }}</td><td>{{ $history->quantity_snapshot }}</td><td>{{ $history->actor->name }}</td><td>{{ $history->comment ?: '—' }}</td></tr>
@empty<tr><td class="empty" colspan="7">No MDB review history yet.</td></tr>@endforelse
</tbody></table></div>
{{ $histories->links() }}
@endsection
