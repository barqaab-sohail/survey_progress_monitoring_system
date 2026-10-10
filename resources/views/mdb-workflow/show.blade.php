@extends('layouts.app')
@section('title', 'MDB survey entry')
@section('content')
<div class="mdb-workflow mdb-entry" id="mdb-entry-workspace">
    <div class="page-head"><div><span class="eyebrow">{{ $batch->project?->name }}</span><h1>MDB survey entry</h1><p>{{ $batch->feeder?->feeder_name }} · Batch #{{ $batch->id }}</p></div><div class="actions"><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">All batches</a><a class="btn btn-primary" data-entry-leave href="{{ route('mdb-workflow.review', $batch) }}">Review &amp; save</a></div></div>
    <p class="entry-steps">1. Upload PDF + GPX <span>›</span> 2. Transformer header <span>›</span> 3. S/E rows <span>›</span> 4. Review &amp; generate MDB</p>
    <section class="card entry-document" aria-label="Survey PDF above the entry form">
        <div class="entry-pdf-toolbar">
            <label class="entry-pdf-file">Survey PDF<select id="entry-pdf-source" aria-label="Survey PDF"></select></label>
            <div class="entry-page-controls"><button type="button" class="btn btn-sm btn-light" id="entry-page-previous" aria-label="Previous PDF page">←</button><label>Page <input id="entry-page" type="number" min="1" value="1" aria-label="Current PDF page"></label><span id="entry-page-total">of 0</span><button type="button" class="btn btn-sm btn-light" id="entry-page-next" aria-label="Next PDF page">→</button></div>
            <div class="actions"><button type="button" class="btn btn-sm btn-light" id="entry-zoom-out" aria-label="Zoom out">−</button><button type="button" class="btn btn-sm btn-light" id="entry-zoom-fit">Fit width</button><button type="button" class="btn btn-sm btn-light" id="entry-zoom-in" aria-label="Zoom in">+</button><button type="button" class="btn btn-sm btn-light" id="entry-rotate" aria-label="Rotate PDF clockwise">Rotate ↻</button><a class="btn btn-sm btn-light" id="entry-pdf-open" target="_blank" rel="noopener">Open PDF</a></div>
            <label class="entry-height">Viewing height <input id="entry-viewer-height" type="range" min="180" max="600" step="20" value="300"><output id="entry-height-value">300 px</output></label>
        </div>
        <div id="entry-pdf-scroll" class="entry-pdf-scroll"><canvas id="entry-pdf-canvas" aria-label="Current survey PDF page"></canvas><p id="entry-pdf-message" role="status">Choose an uploaded survey PDF.</p></div>
        <div class="entry-document-foot"><span id="entry-page-association">PDF navigation keeps your transformer and entered fields.</span>@can('mdb-workflow.edit')<button type="button" class="btn btn-sm btn-light" id="entry-continue">Continue Current Transformer</button><button type="button" class="btn btn-sm btn-light" data-entry-add-transformer>Add New Transformer</button>@endcan</div>
    </section>
    <div class="entry-save-bar"><span id="entry-save-status" role="status" aria-live="polite">Saved records loaded.</span><button type="button" class="btn btn-sm btn-light" id="entry-refresh">Refresh saved records</button></div>
    <div id="entry-errors" class="entry-error-summary" role="alert" hidden></div>
    <noscript><p class="entry-error-summary">Enable JavaScript to use the PDF viewer and row editor. <a href="{{ route('mdb-workflow.advanced', $batch) }}">Open the existing record editor</a>.</p></noscript>
    <section class="card entry-transformer">
        <div class="entry-transformer-bar"><div class="entry-select-filter"><label for="entry-transformer-search">Active transformer</label><input id="entry-transformer-search" type="search" placeholder="Search transformer code" aria-label="Search transformer codes"><select id="entry-transformer" aria-label="Active transformer"></select></div>@can('mdb-workflow.edit')<button type="button" class="btn btn-light" data-entry-add-transformer>Add Transformer</button>@endcan</div>
        <details id="entry-header-panel"><summary>Transformer header <span id="entry-header-caption"></span></summary>
            @can('mdb-workflow.edit')<form id="entry-header-form" autocomplete="off"><div class="entry-fields">
                @foreach(['substation_name'=>'Substation','feeder_name'=>'Feeder name','feeder_identifier'=>'Feeder code'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                <label>Transformer code<input name="code" maxlength="255" required></label>
                @foreach(['division'=>'Division','subdivision'=>'Subdivision','subdivision_code'=>'Subdivision code'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                <label>Transformer capacity (kVA)<input name="capacity_kva" type="number" min="0.001" max="1000000" step="any"></label>
                @foreach(['make'=>'Transformer make','inspector'=>'Inspector','location'=>'Location'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                @if($batch->staged_workflow)<label>Client-provided transformer information (required for the first transformer)<input name="header[client_transformer_information]" maxlength="500"></label>@endif
                <label>Survey date<input name="header[survey_date]" type="date"></label>
                <label>Mounting arrangement<select name="header[mounting]"><option value="">Not entered</option><option>Single Pole</option><option>Double Pole</option><option>Pad</option></select></label>
                <label>Service category<select name="header[service_category]"><option value="">Not entered</option><option>General Duty</option><option>Dedicated</option></select></label>
                <label>Group / team number<input name="header[team_group]" maxlength="40"></label><label>Transformer complete waypoint (only if ambiguous)<input name="header[source_survey_identifier]" inputmode="numeric" maxlength="11" placeholder="GGDDMMYYWWW"></label>
                <label class="entry-select-filter">Transformer GPS waypoint<input type="search" data-entry-filter="header-waypoint" placeholder="Search waypoint" aria-label="Search transformer waypoint"><select name="source_waypoint_id" id="header-waypoint"></select></label><input name="header[substation_identifier]" type="hidden">
            </div><div class="mdb-form-actions"><button class="btn btn-primary" type="submit">Save transformer header</button><small>Saving on the displayed PDF page confirms its header association.</small></div></form>
            @else<div id="entry-readonly-header" class="entry-fields"></div>@endcan
        </details>
    </section>
    @can('mdb-workflow.edit')<section class="card" id="entry-row-panel">
        <div class="row-card-head"><h2 id="entry-row-title">Enter S/E row</h2><span id="entry-row-page"></span><button type="button" class="btn btn-sm btn-light" id="entry-use-page">Use displayed PDF page</button></div>
        <p id="entry-project-settings" class="entry-setting-note"></p>
        <button type="button" id="entry-unit-convert" class="btn btn-sm btn-light" hidden>Convert draft to project unit</button>
        <form id="entry-row-form" autocomplete="off"><div class="entry-fields entry-row-identification">
            <label>S/E<select name="designation"><option value="S">S - Start</option><option value="E">E - End</option></select></label>
            <label>Group<input name="group_number" inputmode="numeric" maxlength="2" pattern="[0-9]{2}" placeholder="01"></label>
            <label>Date (DD/MM/YYYY)<input name="row_date" inputmode="numeric" maxlength="10" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" placeholder="08/10/2026"></label>
            <label>GPS number<input name="waypoint_reference" list="entry-waypoint-options" inputmode="numeric" maxlength="3" pattern="[0-9]{3}" placeholder="001"></label>
            <label>Complete waypoint<input name="composite_identifier" id="entry-composite" readonly placeholder="GGDDMMYYWWW"></label>
            <label>Section pair<select name="pair_number" id="entry-pair"></select></label>
        </div><div class="entry-identity-foot"><small id="entry-waypoint-status"></small><small id="entry-group-date-status" class="entry-inheritance"></small>
            <details><summary>Paste Complete Waypoint</summary><div class="entry-paste-controls"><input id="entry-paste-waypoint" inputmode="numeric" maxlength="11" placeholder="01081026001" aria-label="Paste complete waypoint"><button type="button" id="entry-paste-apply" class="btn btn-sm btn-light">Apply waypoint</button><small id="entry-paste-rule"></small></div></details>
        </div><div id="entry-gpx-ambiguity" class="entry-source-resolution" hidden><label>Resolve GPX source<select name="gpx_source_id" id="entry-gpx" aria-label="Resolve GPX source ambiguity"></select></label><small>Select the file containing this surveyed point. Its recording date is supporting evidence.</small></div>
        <input type="hidden" name="source_pdf_id"><input type="hidden" name="source_page"><input type="hidden" name="source_row"><input type="hidden" name="pole_height_unit">
        <div class="entry-copy-bar"><button type="button" class="btn btn-sm btn-light" id="entry-copy-endpoint">Use previous E waypoint for this S</button><button type="button" class="btn btn-sm btn-light" id="entry-copy-conductors">Copy Previous Conductors</button><button type="button" class="btn btn-sm btn-light" id="entry-copy-equipment">Copy Previous Equipment</button><button type="button" class="btn btn-sm btn-light" id="entry-copy-both">Copy Previous Values</button><small>Copy Previous Values copies conductors and equipment. Counts, PV and intersection stay as entered.</small></div>
        <fieldset class="entry-compact-fieldset entry-electrical-equipment"><legend>Conductors, automatic phase and equipment at this S/E row</legend>
            <div class="entry-fields entry-electrical-equipment-fields entry-compact-fields">
                @foreach(['R'=>'R','Y'=>'Y','B'=>'B','N'=>'Neutral'] as $key=>$label)<label>{{ $label }}<select id="entry-conductor-{{ $key }}" name="conductors[{{ $key }}]" data-entry-conductor data-compact-select aria-label="{{ $label }} conductor"></select></label>@endforeach
                <label class="entry-phase-field">Phase<input id="entry-phase" readonly aria-describedby="entry-phase-note"><small id="entry-phase-note" hidden></small></label>
                <label>Type<select name="equipment_type" id="entry-equipment" data-compact-select aria-label="Equipment type"></select></label>
                <label>Pole class<select name="pole_class" id="entry-pole-class" data-compact-select aria-label="Pole class"></select></label>
                <label class="entry-pole-height-field">Pole height<div class="entry-height-input"><input name="pole_height" type="number" min="0" max="200" step="any" inputmode="decimal"><span id="entry-unit-label"></span></div></label>
            </div>
            <small id="entry-conductor-copy-status" class="entry-inheritance"></small><small id="entry-equipment-copy-status" class="entry-inheritance"></small>
        </fieldset>
        <fieldset><legend>Consumer counts — blank and zero are different</legend><div class="entry-consumer-fields">
            @foreach(\App\Services\Mdb\EntryLookups::CONSUMERS as $key=>$description)<label><strong>{{ strtoupper($key) }}</strong><input name="consumers[{{ $key }}]" type="number" min="0" max="1000000" step="1" aria-label="{{ strtoupper($key) }} consumer count"><small>{{ $description }}</small></label>@endforeach
        </div><div class="entry-count-foot"><label class="mdb-check"><input name="intersection" type="checkbox" value="1">Intersection (Int)</label><span id="entry-consumer-total">Entered consumer total: 0</span><small>Intersection is excluded. Section counts use each S/E entry once; PV counts remain separate in export.</small></div></fieldset>
        <details class="entry-pv"><summary>Optional PV details <span id="entry-pv-count"></span></summary><div id="entry-pv-records"></div><button type="button" class="btn btn-sm btn-light" id="entry-pv-add">Add PV record</button></details>
        <div class="entry-save-actions"><button type="submit" class="btn btn-primary" id="entry-save-next">Save &amp; Add Next</button><button type="button" class="btn btn-light" id="entry-save-draft">Save Draft</button><button type="button" class="btn btn-light" id="entry-row-new">New row / cancel edit</button><small>Ctrl+Enter: save &amp; next · Ctrl+S: save draft. Drafts may contain incomplete pairs.</small></div></form>
    </section>@endcan
    <section class="card" id="entry-saved"><div class="row-card-head"><h2>Saved rows for active transformer</h2><span id="entry-saved-count"></span></div><div id="entry-saved-rows"></div><div id="entry-legacy-records"></div><a class="btn btn-light" data-entry-leave id="entry-review-link" href="{{ route('mdb-workflow.review', $batch) }}">Review all rows &amp; generate MDB</a></section>
    <details class="card entry-source-files"><summary>Upload PDF + GPX / source processing</summary><div id="entry-sources-list"></div>
        @can('mdb-workflow.upload')<form method="POST" action="{{ route('mdb-workflow.sources.store', $batch) }}" enctype="multipart/form-data">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}" data-entry-revision><div class="entry-fields"><label>Add PDF or GPX<input name="file" type="file" required accept=".pdf,.gpx"></label></div><div class="mdb-form-actions"><button class="btn btn-light">Upload source</button></div></form>@endcan
        <a href="{{ route('mdb-workflow.advanced', $batch) }}#sources">Source versions and Google Drive import</a>
    </details><datalist id="entry-waypoint-options"></datalist>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}"><link rel="stylesheet" href="{{ asset('css/mdb-entry.css') }}">@endpush
@push('scripts')
<script>window.mdbEntryBoot = {{ \Illuminate\Support\Js::from($editorData + ['base_url' => route('mdb-workflow.show', $batch), 'settings_url' => route('mdb-workflow.config', $batch->project_id), 'pdfjs_url' => asset('vendor/pdfjs/build/pdf.min.mjs'), 'pdfjs_worker' => asset('vendor/pdfjs/build/pdf.worker.min.mjs'), 'pdfjs_assets' => asset('vendor/pdfjs').'/', 'storage_key' => 'mdb-entry:'.auth()->id().':'.$batch->id, 'can_edit' => auth()->user()->can('mdb-workflow.edit'), 'active_transformer_id' => (int) request('transformer_id')]) }};</script><script src="{{ asset('js/mdb-entry.js') }}" type="module"></script>
@endpush
