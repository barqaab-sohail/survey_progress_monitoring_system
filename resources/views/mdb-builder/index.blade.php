@extends('layouts.app')
@section('title', 'Transformer MDB workspace')
@section('content')
<div class="page-head"><div><h1>Transformer MDB workspace</h1><p>Enter a paper survey, link its GPX waypoints, and create a transformer network MDB.</p></div>
@if(auth()->user()->hasRole('super_admin'))<a class="btn btn-primary" href="{{ route('mdb-builder.create') }}">Enter paper survey</a>@endif</div>
@if(auth()->user()->hasRole('super_admin'))
<section class="card"><h2>Start from an Android survey</h2><p>Copy a submitted survey into an MDB workspace, then check its sections and GPS coordinates.</p>
<form method="GET" action="{{ route('mdb-builder.create') }}" class="actions mdb-copy-form"><label style="flex:1">Submitted survey<select name="source" required><option value="">Select a survey</option>@foreach($surveys as $survey)<option value="{{ $survey->id }}">{{ $survey->transformer_code }} | {{ $survey->feeder->feeder_name }} | {{ $survey->survey_date->format('d M Y') }}</option>@endforeach</select></label><button class="btn btn-light">Copy survey</button></form>
<small>The most recent 100 submitted surveys are listed. The Android source is retained unchanged.</small></section>
@endif
<section class="card table-wrap"><table class="table"><thead><tr><th>Transformer</th><th>Feeder</th><th>Survey date</th><th>GPX</th><th>Source</th><th>Actions</th></tr></thead><tbody>
@forelse($projects as $project)<tr><td>{{ $project->transformer_code }}</td><td>{{ $project->feeder->feeder_code }} | {{ $project->feeder->feeder_name }}</td><td>{{ $project->survey_date->format('d M Y') }}</td><td>{{ count($project->gpx_waypoints ?? []) }} waypoints</td><td>{{ $project->source_field_survey_id ? 'Android survey' : 'Manual entry' }}</td><td><div class="actions">@if(auth()->user()->hasRole('super_admin'))<a class="btn btn-sm btn-light" href="{{ route('mdb-builder.edit', $project) }}">Edit</a>@endif<a class="btn btn-sm btn-light" href="{{ route('mdb-builder.preview', $project) }}">Review network</a></div></td></tr>
@empty<tr><td colspan="6" class="empty">No transformer MDB workspaces yet.</td></tr>@endforelse
</tbody></table></section>
{{ $projects->links() }}
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-builder.css') }}">@endpush
