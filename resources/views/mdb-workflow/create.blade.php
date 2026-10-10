@extends('layouts.app')
@section('title', 'Create survey batch')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>Create survey batch</h1><p>Group the survey's source files and transformer networks under one project, feeder and team.</p></div><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">All batches</a></div>
    <form method="POST" action="{{ route('mdb-workflow.store') }}" enctype="multipart/form-data" class="card">@csrf
        <div class="mdb-fields">
            <label>Project<select name="project_id" required data-mdb-project><option value="">Choose project</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->code }} | {{ $project->name }}</option>@endforeach</select></label>
            <label>Feeder<select name="feeder_id" required data-mdb-project-dependent><option value="">Choose feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" data-project-id="{{ $feeder->project_id }}" @selected((string) old('feeder_id') === (string) $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label>
            <label>Survey team<select name="survey_team_id" required data-mdb-project-dependent><option value="">Choose survey team</option>@foreach($teams as $team)<option value="{{ $team->id }}" data-project-id="{{ $team->project_id }}" @selected((string) old('survey_team_id') === (string) $team->id)>{{ $team->code }} | {{ $team->name }}</option>@endforeach</select></label>
            <label>Survey date<input type="date" name="survey_date" value="{{ old('survey_date', now('Asia/Karachi')->toDateString()) }}" required></label>
            <label>Survey PDF<input name="survey_pdf" type="file" accept=".pdf" required></label><label>GPS GPX<input name="gps_gpx" type="file" accept=".gpx" required></label>
        </div>
        <p class="muted">Both files must finish processing before entry starts. Originals are kept privately while processing runs in the background.</p>
        <button class="btn btn-primary">Create batch &amp; start entry</button>
    </form>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/mdb-workflow.js') }}" defer></script>@endpush
