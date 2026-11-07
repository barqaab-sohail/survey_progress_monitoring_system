@extends('layouts.app')
@section('title', 'Mobile Survey GPX Linking')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/mobile-gpx.css') }}?v=20261106">
@endpush
@section('content')
<div class="mobile-gpx-page">
<div class="page-head"><div><h1>Mobile Survey GPX Linking</h1><p>Upload a shared GPX once, then confirm the matching points for each transformer before downloading JSON.</p></div></div>
<form class="card" method="POST" enctype="multipart/form-data" action="{{ route('mobile-gpx.upload') }}">@csrf
    <div class="gpx-section-head"><h2>Upload shared GPX</h2><p>Select the survey team and upload its GPS session file.</p></div><div class="form-grid gpx-upload-grid">
    <div class="field"><label for="gpx-survey-team">Survey team / group</label><select id="gpx-survey-team" name="survey_team_id" required><option value="">Select team</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team_id'] ?? null) == $team->id)>{{ $team->project?->name }} / {{ $team->name }} / Group {{ \App\Support\SurveyTeamGroup::identifier($team) ?? 'Unconfigured' }} / GPX {{ $team->gpx_group_code ?: $team->group_code }}</option>@endforeach</select></div>
    <div class="field"><label for="gpx-file">GPX file</label><input id="gpx-file" aria-describedby="gpx-file-help" type="file" name="gpx" accept=".gpx" required><small id="gpx-file-help">Group_DDMMYY_Count.gpx, e.g. A_061126_06.gpx. Maximum 10 MB.</small></div></div>
    <div class="gpx-form-actions"><button type="submit" class="btn btn-primary">Upload GPX</button></div>
</form>
<form class="card" method="GET" action="{{ route('mobile-gpx.index') }}"><div class="gpx-section-head"><h2>Find surveys</h2><p>Filter submitted surveys to find the transformer you want to link.</p></div><div class="form-grid gpx-filter-grid">
    <div class="field"><label for="gpx-filter-team">Team</label><select id="gpx-filter-team" name="team_id"><option value="">All assigned teams</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team_id'] ?? null) == $team->id)>{{ $team->name }}</option>@endforeach</select></div>
    <div class="field"><label for="gpx-filter-date">Survey date</label><input id="gpx-filter-date" type="date" name="date" value="{{ $filters['date'] ?? '' }}"></div>
    <div class="field"><label for="gpx-filter-code">Transformer code</label><input id="gpx-filter-code" placeholder="Enter transformer code" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></div></div><div class="gpx-form-actions"><button type="submit" class="btn btn-primary">Filter surveys</button><a class="btn btn-light" href="{{ route('mobile-gpx.index') }}">Clear filters</a></div>
</form>
<section class="card"><div class="gpx-section-head"><h2>Shared GPX files</h2><p>Review uploaded sessions and their linked transformer counts.</p></div><div class="table-wrap"><table class="table gpx-sources-table"><thead><tr><th>File</th><th>Team / date</th><th>Expected transformers</th><th>Linked current revisions</th></tr></thead><tbody>
@forelse($sources as $source)<tr><td>{{ $source->original_name }}</td><td>{{ $source->team->name }} / {{ $source->survey_date->format('d M Y') }}</td><td>{{ $source->expected_transformers }}</td><td>{{ $source->linked_count }} @if($source->linked_count > $source->expected_transformers)<strong>— count exceeds filename; investigate</strong>@endif</td></tr>@empty<tr><td colspan="4" class="empty">No shared GPX uploaded in your assigned projects.</td></tr>@endforelse
</tbody></table></div><p>Counts help reconcile the session. Each transformer must pass its own waypoint checks.</p></section>
@forelse($surveys as $survey)
@php($link = $workflow->current($survey))
<article class="card gpx-survey"><h2><a href="{{ route('mobile-gpx.show', $survey->client_uuid) }}">{{ $survey->survey_name }}</a></h2><p>{{ $survey->feeder->feeder_code }} / {{ $survey->feeder->feeder_name }} · {{ $survey->team->name }} · {{ $survey->collector->name }} · Revision {{ $survey->revision }}</p><p>{{ $link ? 'GPX confirmed' : 'GPX pending or confirmation outdated' }} · {{ count($survey->rows) }} S/E rows</p><a class="btn btn-primary" href="{{ route('mobile-gpx.show', $survey->client_uuid) }}">Link GPX / download JSON</a></article>
@empty<div class="card empty">No submitted mobile surveys match these filters.</div>@endforelse
{{ $surveys->links() }}
</div>
@endsection
