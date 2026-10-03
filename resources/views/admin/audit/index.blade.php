@extends('layouts.app')
@section('title','Audit Log')
@section('content')
<div class="page-head"><div><h1>Audit Log</h1><p>Append-only operational and administrative history.</p></div><form><div class="field"><input name="action" value="{{ request('action') }}" placeholder="Filter action"></div></form></div>
<div class="card table-wrap"><table class="table"><thead><tr><th>Date & time</th><th>User</th><th>Organization</th><th>Action</th><th>Record</th><th>Reason</th><th>Change</th></tr></thead><tbody>@foreach($logs as $log)<tr><td>{{ $log->created_at->format('d M Y h:i A') }}</td><td>{{ $log->user?->name ?: 'System' }}</td><td>{{ $log->organization?->name ?: '—' }}</td><td><strong>{{ $log->action }}</strong></td><td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td><td>{{ $log->reason ?: '—' }}</td><td><details><summary>View JSON</summary><pre style="max-width:480px;white-space:pre-wrap">{{ json_encode(['old'=>$log->old_values,'new'=>$log->new_values], JSON_PRETTY_PRINT) }}</pre></details></td></tr>@endforeach</tbody></table></div>{{ $logs->links() }}
@endsection
