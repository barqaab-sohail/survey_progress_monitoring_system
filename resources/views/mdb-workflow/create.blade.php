@extends('layouts.app')
@section('title', 'Create survey batch')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>Create survey batch</h1><p>Group the survey's source files and transformer networks under one project, feeder and team.</p></div><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">All batches</a></div>
    @php($defaultProject = $projects->firstWhere('code', 'HAZECO-TDL') ?? $projects->firstWhere('code', 'HAZECO'))
    <form method="POST" action="{{ route('mdb-workflow.store') }}" enctype="multipart/form-data" class="card" data-batch-create data-drive-preview="{{ route('mdb-workflow.drive-preview') }}">@csrf
        <h2>1. Select the survey PDF</h2>
        @include('mdb-workflow.partials.create-sources')
        <p data-create-status role="status" aria-live="polite"></p>
        <div class="mdb-create-layout">
            <section class="mdb-create-preview" aria-label="Survey PDF preview">
                <p class="muted" data-preview-placeholder>Select a PDF above to view it here.</p>
                <a class="btn btn-light" data-preview-open hidden target="_blank" rel="noopener">Open PDF in a new tab</a>
                <iframe title="Survey PDF preview" data-create-pdf hidden></iframe>
            </section>
            <section><h2>2. Enter details from the PDF</h2>
        <div class="mdb-fields">
            <label>Project<select name="project_id" required data-mdb-project><option value="">Choose project</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $defaultProject?->id) === (string) $project->id)>{{ $project->code }} | {{ $project->name }}</option>@endforeach</select></label>
            <label>Feeder<select name="feeder_id" required data-mdb-project-dependent><option value="">Choose feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" data-project-id="{{ $feeder->project_id }}" @selected((string) old('feeder_id') === (string) $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label>
            <label>Survey team<select name="survey_team_id" required data-mdb-project-dependent><option value="">Choose survey team</option>@foreach($teams as $team)<option value="{{ $team->id }}" data-project-id="{{ $team->project_id }}" @selected((string) old('survey_team_id') === (string) $team->id)>{{ $team->code }} | {{ $team->name }}</option>@endforeach</select></label>
            <label>Survey date<input type="date" name="survey_date" value="{{ old('survey_date') }}" required></label>

        </div>
        <p class="muted">Both files must finish processing before entry starts. Originals are kept privately while processing runs in the background.</p>
        <button class="btn btn-primary">Create batch &amp; start entry</button>
            </section>
        </div>
    </form>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/mdb-workflow.js') }}" defer></script><script src="{{ asset('js/mdb-batch-create.js') }}" defer></script>@endpush
