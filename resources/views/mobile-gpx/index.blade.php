@extends('layouts.app')
@section('title', 'Mobile Survey GPX Linking')
@section('content')
<div class="page-head"><div><h1>Mobile Survey GPX Linking</h1><p>Upload a shared GPX once, then confirm the matching points for each transformer before downloading JSON.</p></div></div>
<form class="card" method="POST" enctype="multipart/form-data" action="{{ route('mobile-gpx.upload') }}">@csrf
    <h2>Upload shared GPX</h2><div class="form-grid">
    <label>Survey team / group<select name="survey_team_id" required><option value="">Select team</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team_id'] ?? null) == $team->id)>{{ $team->project?->name }} / {{ $team->name }} / Group {{ \App\Support\SurveyTeamGroup::identifier($team) ?? 'Unconfigured' }} / GPX {{ $team->gpx_group_code ?: $team->group_code }}</option>@endforeach</select></label>
    <label>GPX file<input type="file" name="gpx" accept=".gpx" required><small>Group_DDMMYY_Count.gpx, e.g. A_061126_06.gpx. Maximum 10 MB.</small></label></div>
    <button class="btn btn-primary">Upload GPX</button>
</form>
<form class="card" method="GET"><div class="form-grid">
    <label>Team<select name="team_id"><option value="">All assigned teams</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team_id'] ?? null) == $team->id)>{{ $team->name }}</option>@endforeach</select></label>
    <label>Survey date<input type="date" name="date" value="{{ $filters['date'] ?? '' }}"></label>
    <label>Transformer code<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label></div><button class="btn">Filter surveys</button> <a href="{{ route('mobile-gpx.index') }}">Clear</a>
</form>
<section class="card"><h2>Shared GPX files</h2><div style="overflow:auto"><table><thead><tr><th>File</th><th>Team / date</th><th>Expected transformers</th><th>Linked current revisions</th></tr></thead><tbody>
@forelse($sources as $source)<tr><td>{{ $source->original_name }}</td><td>{{ $source->team->name }} / {{ $source->survey_date->format('d M Y') }}</td><td>{{ $source->expected_transformers }}</td><td>{{ $source->linked_count }} @if($source->linked_count > $source->expected_transformers)<strong>— count exceeds filename; investigate</strong>@endif</td></tr>@empty<tr><td colspan="4">No shared GPX uploaded in your assigned projects.</td></tr>@endforelse
</tbody></table></div><p>Counts help reconcile the session. Each transformer must pass its own waypoint checks.</p></section>
@forelse($surveys as $survey)
@php($link = $workflow->current($survey))
<article class="card"><h2><a href="{{ route('mobile-gpx.show', $survey->client_uuid) }}">{{ $survey->survey_name }}</a></h2><p>{{ $survey->feeder->feeder_code }} / {{ $survey->feeder->feeder_name }} · {{ $survey->team->name }} · {{ $survey->collector->name }} · Revision {{ $survey->revision }}</p><p>{{ $link ? 'GPX confirmed' : 'GPX pending or confirmation outdated' }} · {{ count($survey->rows) }} S/E rows</p><a class="btn btn-primary" href="{{ route('mobile-gpx.show', $survey->client_uuid) }}">Link GPX / download JSON</a></article>
@empty<div class="card">No submitted mobile surveys match these filters.</div>@endforelse
{{ $surveys->links() }}
@endsection
