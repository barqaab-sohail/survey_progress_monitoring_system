@extends('layouts.app')
@section('title', 'Create survey batch')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>Create survey batch</h1><p>Group the survey's source files and transformer networks under one project and feeder.</p></div><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">All batches</a></div>
    @php($defaultProject = $projects->firstWhere('code', 'HAZECO-TDL') ?? $projects->firstWhere('code', 'HAZECO'))
    <form method="POST" action="{{ route('mdb-workflow.store') }}" enctype="multipart/form-data" class="card" data-batch-create data-drive-preview="{{ route('mdb-workflow.drive-preview') }}">@csrf
        <h2>1. Select the survey PDF</h2>
        @include('mdb-workflow.partials.create-sources')
        <p data-create-status role="status" aria-live="polite"></p>
        <div class="mdb-create-layout">
            <section class="mdb-create-preview" aria-label="Survey PDF preview">
                <p class="muted" data-preview-placeholder>Select a PDF above to view it here.</p>
                <div class="mdb-create-pdf-toolbar" data-create-toolbar hidden>
                    <button type="button" class="btn btn-light" data-pdf-previous aria-label="Previous PDF page" disabled>&larr;</button>
                    <label>Page <input data-pdf-page type="number" min="1" value="1" aria-label="Current PDF page" disabled></label><span data-pdf-total>of 0</span>
                    <button type="button" class="btn btn-light" data-pdf-next aria-label="Next PDF page" disabled>&rarr;</button>
                    <button type="button" class="btn btn-light" data-pdf-zoom-out aria-label="Zoom out" disabled>&minus;</button>
                    <button type="button" class="btn btn-light" data-pdf-fit disabled>Fit width</button>
                    <button type="button" class="btn btn-light" data-pdf-zoom-in aria-label="Zoom in" disabled>+</button>
                    <button type="button" class="btn btn-light" data-pdf-rotate disabled>Rotate &#8635;</button>
                    <a class="btn btn-light" data-preview-open hidden target="_blank" rel="noopener">Open PDF</a>
                    <label>Viewing height <input data-pdf-height type="range" min="180" max="800" step="20" value="300"><output data-pdf-height-value>300 px</output></label>
                </div>
                <div class="mdb-create-pdf-scroll" data-pdf-scroll hidden><canvas data-pdf-canvas hidden aria-label="Current survey PDF page"></canvas></div>
                <iframe title="Survey PDF preview" data-create-pdf hidden></iframe>
            </section>
            <section><h2>2. Enter details from the PDF</h2>
        <div class="mdb-fields">
            <label>Project<select name="project_id" required data-mdb-project><option value="">Choose project</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $defaultProject?->id) === (string) $project->id)>{{ $project->code }} | {{ $project->name }}</option>@endforeach</select></label>
            <label>Feeder<select name="feeder_id" required data-mdb-project-dependent data-mdb-feeder-dependent disabled><option value="">Choose a feeder</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" data-project-id="{{ $feeder->project_id }}" @selected((string) old('feeder_id') === (string) $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select><small>Choose the feeder shown on the source files.</small></label>
            @if(auth()->user()->hasRole('project_manager'))<label>Assign Data Entry Operator<select name="entry_assigned_to" required><option value="">Choose operator</option>@foreach($entryOperators as $operator)<option value="{{ $operator->id }}" @selected((string) old('entry_assigned_to') === (string) $operator->id)>{{ $operator->name }}</option>@endforeach</select><small>This operator receives access to the batch without needing survey team membership.</small></label>@endif
            <label>Survey date<input type="date" name="survey_date" value="{{ old('survey_date') }}" required></label>

        </div>
        <p class="muted">Both files must finish processing before entry starts. Originals are kept privately while processing runs in the background.</p>
        <button class="btn btn-primary">Create batch &amp; start entry</button>
            </section>
        </div>
    </form>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css').'?v='.filemtime(public_path('css/mdb-workflow.css')) }}">@endpush
@push('scripts')<script src="{{ asset('js/mdb-workflow.js') }}" defer></script><script src="{{ asset('js/mdb-batch-create.js').'?v='.filemtime(public_path('js/mdb-batch-create.js')) }}" data-pdf-viewer="{{ asset('js/mdb-create-pdf.js').'?v='.filemtime(public_path('js/mdb-create-pdf.js')) }}" data-pdfjs="{{ asset('vendor/pdfjs/build/pdf.min.mjs') }}" defer></script>@endpush
