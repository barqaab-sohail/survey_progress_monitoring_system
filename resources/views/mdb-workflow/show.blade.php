@extends('layouts.app')
@section('title', 'MDB survey entry')
@section('content')
<div class="mdb-workflow mdb-entry" id="mdb-entry-workspace">
    <div class="page-head"><div><span class="eyebrow">{{ $batch->project?->name }}</span><h1>MDB survey entry</h1><p>{{ $batch->feeder?->feeder_name }} · Batch #{{ $batch->id }}</p></div><div class="actions"><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">All batches</a><a class="btn btn-primary" data-entry-leave href="{{ route('mdb-workflow.review', $batch) }}">Review saved survey</a></div></div>
    <p class="entry-steps">1. Upload PDF + GPX <span>›</span> 2. Transformer header <span>›</span> 3. S/E rows <span>›</span> 4. Submit for Verification</p>
    <section class="card entry-document" aria-label="Survey PDF above the entry form">
        <div class="entry-pdf-toolbar">
            <label class="entry-pdf-file">Survey PDF<select id="entry-pdf-source" aria-label="Survey PDF"></select></label>
            <div class="entry-page-controls"><button type="button" class="btn btn-sm btn-light" id="entry-page-previous" aria-label="Previous PDF page">←</button><label>Page <input id="entry-page" type="number" min="1" value="1" aria-label="Current PDF page"></label><span id="entry-page-total">of 0</span><button type="button" class="btn btn-sm btn-light" id="entry-page-next" aria-label="Next PDF page">→</button></div>
            <div class="actions"><button type="button" class="btn btn-sm btn-light" id="entry-zoom-out" aria-label="Zoom out">−</button><button type="button" class="btn btn-sm btn-light" id="entry-zoom-fit">Fit width</button><button type="button" class="btn btn-sm btn-light" id="entry-zoom-in" aria-label="Zoom in">+</button><button type="button" class="btn btn-sm btn-light" id="entry-rotate" aria-label="Rotate PDF clockwise">Rotate ↻</button><a class="btn btn-sm btn-light" id="entry-pdf-open" target="_blank" rel="noopener">Open PDF</a></div>
            <label class="entry-height">Viewing height <input id="entry-viewer-height" type="range" min="180" max="600" step="20" value="240"><output id="entry-height-value">240 px</output></label>
        </div>
        <div id="entry-pdf-scroll" class="entry-pdf-scroll"><div id="entry-pdf-stage" class="entry-pdf-stage"><canvas id="entry-pdf-canvas" aria-label="Current survey PDF page"></canvas><div id="entry-pdf-table-area" class="entry-pdf-table-area" hidden aria-hidden="true"></div><div id="entry-pdf-entered-area" class="entry-pdf-entered-area" hidden aria-hidden="true"><span></span></div><div id="entry-pdf-rows" class="entry-pdf-rows" aria-label="Selectable survey rows"></div><div id="entry-pdf-area" class="entry-pdf-area" hidden></div></div><p id="entry-pdf-message" role="status">Choose an uploaded survey PDF.</p></div>
        <div class="entry-progress-controls">
            <label>PDF row <select id="entry-pdf-row" aria-label="Select survey PDF row"></select></label>
            <span id="entry-pdf-progress" role="status" aria-live="polite"></span>
            <button type="button" class="btn btn-sm btn-light" id="entry-pdf-pending">Go to next pending</button>
            @if($canEdit)<button type="button" class="btn btn-sm btn-light" id="entry-pdf-mark">Mark Entered</button><button type="button" class="btn btn-sm btn-light" id="entry-pdf-undo">Undo Entered</button>
            <details id="entry-pdf-setup"><summary>Set up page rows</summary><div class="entry-progress-controls"><label>S/E rows on this page <input id="entry-pdf-row-count" type="number" min="1" max="200" value="10"></label><button type="button" class="btn btn-sm btn-light" id="entry-pdf-draw">Select row area</button><button type="button" class="btn btn-sm btn-light" id="entry-pdf-layout-save" disabled>Save page rows</button></div><small id="entry-pdf-setup-help">Count each S and E as a separate row. At the original rotation, drag around only the S/E table body, from the top of the first row to the bottom of the last. The area is divided into equal rows; check alignment before entering. Set up each page separately.</small></details>@endif
        </div>
        <div class="entry-progress-note">Saving marks the selected row Entered. Green grows through consecutive saved rows; the dashed row is next pending. Undo keeps the record. <details><summary>Progress help</summary>Progress is stored separately from the original PDF. Once records are linked, the row count stays fixed; redraw the area to adjust alignment.</details></div>
        <div class="entry-document-foot"><span id="entry-page-association">PDF navigation keeps your transformer and entered fields.</span>@if($canEdit)<button type="button" class="btn btn-sm btn-light" id="entry-continue">Continue Current Transformer</button><button type="button" class="btn btn-sm btn-light" data-entry-add-transformer>Add New Transformer</button>@endif</div>
    </section>
    <div class="entry-save-bar"><span id="entry-save-status" role="status" aria-live="polite">Saved records loaded.</span><div id="entry-errors" class="entry-error-summary" role="alert" tabindex="-1" hidden></div><button type="button" class="btn btn-sm btn-light" id="entry-refresh">Refresh saved records</button></div>
    <noscript><p class="entry-error-summary">Enable JavaScript to use the PDF viewer and row editor. <a href="{{ route('mdb-workflow.advanced', $batch) }}">Open the existing record editor</a>.</p></noscript>
    <section class="card entry-transformer">
        <div class="entry-transformer-bar"><div class="entry-select-filter"><label for="entry-transformer-search">Active transformer</label><input id="entry-transformer-search" type="search" placeholder="Search transformer code" aria-label="Search transformer codes"><select id="entry-transformer" aria-label="Active transformer"></select></div>@if($canEdit)<button type="button" class="btn btn-light" data-entry-add-transformer>Add Transformer</button>@endif</div>
        <details id="entry-header-panel"><summary>Transformer header <span id="entry-header-caption"></span></summary>
            @if($canEdit)<form id="entry-header-form" autocomplete="off" novalidate><div class="entry-fields">
                @foreach(['substation_name'=>'Substation','feeder_name'=>'Feeder name'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                <label>Transformer code<input name="code" maxlength="255" required></label>
                <label>Feeder code<input name="header[feeder_identifier]" maxlength="500"></label>
                @foreach(['division'=>'Division','subdivision'=>'Subdivision','subdivision_code'=>'Subdivision code'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                <label>Transformer capacity (kVA)<input name="capacity_kva" type="number" min="0.001" max="1000000" step="any"></label>
                <label>Transformer make<input name="header[make]" maxlength="500"></label>
                <label>Mounting arrangement<select name="header[mounting]"><option value="">Not entered</option><option>Single Pole</option><option>Double Pole</option><option>Pad</option></select></label>
                <label>Service category<select name="header[service_category]"><option value="">Not entered</option><option>General Duty</option><option>Dedicated</option></select></label>
                <label>Survey date<input name="header[survey_date]" type="date"></label>
                @foreach(['inspector'=>'Inspector','location'=>'Location'] as $key=>$label)<label>{{ $label }}<input name="header[{{ $key }}]" maxlength="500"></label>@endforeach
                @if($batch->staged_workflow)<label>Client-provided transformer information (required for the first transformer)<input name="header[client_transformer_information]" maxlength="500"></label>@endif
                <label>Group / team number<input name="header[team_group]" maxlength="40"></label><label>Transformer complete waypoint (only if ambiguous)<input name="header[source_survey_identifier]" inputmode="numeric" maxlength="11" placeholder="GGDDMMYYWWW"></label>
                <label class="entry-select-filter">Transformer GPS waypoint<input type="search" data-entry-filter="header-waypoint" placeholder="Search waypoint" aria-label="Search transformer waypoint"><select name="source_waypoint_id" id="header-waypoint"></select></label><input name="header[substation_identifier]" type="hidden">
            </div><div class="mdb-form-actions"><button class="btn btn-primary" type="submit">Save transformer header</button><small>Saving on the displayed PDF page confirms its header association.</small></div></form>
            @else<div id="entry-readonly-header" class="entry-fields"></div>@endif
        </details>
    </section>
    @if($canEdit)<section class="card" id="entry-row-panel">
        <div class="row-card-head"><h2 id="entry-row-title">Enter S/E row</h2><span id="entry-row-page"></span><button type="button" class="btn btn-sm btn-light" id="entry-use-page">Use displayed PDF page</button></div>
        <p id="entry-project-settings" class="entry-setting-note"></p>
        <button type="button" id="entry-unit-convert" class="btn btn-sm btn-light" hidden>Convert draft to project unit</button>
        <div class="entry-copy-bar"><button type="button" class="btn btn-sm btn-light" id="entry-copy-both">Copy Previous Values</button><button type="button" class="btn btn-sm btn-light" id="entry-copy-conductors">Conductors only</button><button type="button" class="btn btn-sm btn-light" id="entry-copy-equipment">Equipment + height only</button><small>Copies R/Y/B/neutral, type, class and pole/tower height. All stay editable. IDs, GPS, counts, PV and intersection are kept as entered.</small></div>
        <form id="entry-row-form" autocomplete="off" novalidate aria-describedby="entry-tab-help">
        <input type="hidden" name="source_pdf_id"><input type="hidden" name="source_page"><input type="hidden" name="source_row"><input type="hidden" name="pole_height_unit">
        <div class="entry-observation-grid"><fieldset class="entry-compact-fieldset entry-identity-fieldset"><legend>Row identity</legend><div class="entry-fields entry-row-identification">
            <label>S/E<select name="designation"><option value="S">S - Start</option><option value="E">E - End</option></select></label>
            <label>Group<input name="group_number" inputmode="numeric" maxlength="2" pattern="[0-9]{2}" placeholder="01"></label>
            <label>Date (DD/MM/YYYY)<input name="row_date" inputmode="numeric" maxlength="10" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" placeholder="08/10/2026"></label>
            <label>GPS number<input name="waypoint_reference" list="entry-waypoint-options" inputmode="numeric" maxlength="3" pattern="[0-9]{3}" placeholder="001"></label>
        </div></fieldset>
        <fieldset class="entry-compact-fieldset entry-electrical-equipment"><legend>Phase / conductors / equipment</legend>
            <div class="entry-fields entry-electrical-equipment-fields entry-compact-fields">
                <label class="entry-phase-field">Phase<input id="entry-phase" readonly tabindex="-1" aria-describedby="entry-phase-note"><small id="entry-phase-note" hidden></small></label>
                @foreach(['R'=>'R','Y'=>'Y','B'=>'B','N'=>'Neutral'] as $key=>$label)<label>{{ $label }}<select id="entry-conductor-{{ $key }}" name="conductors[{{ $key }}]" data-entry-conductor data-compact-select aria-label="{{ $label }} conductor"></select></label>@endforeach
                <label>Type<select name="equipment_type" id="entry-equipment" data-compact-select aria-label="Equipment type"></select></label>
                <label>Pole class<select name="pole_class" id="entry-pole-class" data-compact-select aria-label="Pole class"></select></label>
                <label class="entry-pole-height-field">Pole / tower height<div class="entry-height-input"><input name="pole_height" type="number" min="0" max="200" step="any" inputmode="decimal"><span id="entry-unit-label"></span></div></label>
            </div>
        </fieldset></div>
        <div class="entry-identity-foot"><small id="entry-waypoint-status"></small><small id="entry-group-date-status" class="entry-inheritance"></small><small id="entry-conductor-copy-status" class="entry-inheritance"></small><small id="entry-equipment-copy-status" class="entry-inheritance"></small></div>
        <fieldset class="entry-compact-fieldset entry-count-fieldset"><legend>Consumer counts <span class="entry-legend-note">— blank means not entered; 0 means none</span></legend><div class="entry-consumer-fields">
            @foreach(\App\Services\Mdb\EntryLookups::CONSUMERS as $key=>$description)<label title="{{ $description }}"><strong>{{ strtoupper($key) }}</strong><input name="consumers[{{ $key }}]" type="number" min="0" max="1000000" step="1" aria-label="{{ strtoupper($key) }} — {{ $description }} consumer count"></label>@endforeach
            <label class="entry-intersection-field" title="Intersection, excluded from consumer total"><strong>Int</strong><input name="intersection" type="checkbox" value="1" aria-label="Intersection (Int)"></label>
        </div><div class="entry-count-foot"><span id="entry-consumer-total">Entered consumer total: 0</span><details><summary>Consumer code key</summary><p>@foreach(\App\Services\Mdb\EntryLookups::CONSUMERS as $key=>$description)<span><strong>{{ strtoupper($key) }}</strong>: {{ $description }}@if(!$loop->last) · @endif</span>@endforeach</p>Intersection is excluded from the total. PV counts remain separate in export.</details></div></fieldset>
        <details class="entry-pv"><summary>Optional PV details <span id="entry-pv-count"></span></summary><div id="entry-pv-records"></div><button type="button" class="btn btn-sm btn-light" id="entry-pv-add">Add PV record</button></details>
        <div id="entry-gpx-ambiguity" class="entry-source-resolution" hidden><label>Resolve GPX source<select name="gpx_source_id" id="entry-gpx" aria-label="Resolve GPX source ambiguity"></select></label><small>Select the file containing this surveyed point. Its recording date is supporting evidence.</small></div>
        <details class="entry-row-options"><summary>Row identity / section pairing <span id="entry-pair-caption"></span></summary><div class="entry-fields">
            <label>Complete waypoint<input name="composite_identifier" id="entry-composite" readonly tabindex="-1" placeholder="GGDDMMYYWWW"></label>
            <label>Section pair<select name="pair_number" id="entry-pair"></select></label>
        </div><div class="entry-paste-controls"><label>Paste Complete Waypoint<input id="entry-paste-waypoint" inputmode="numeric" maxlength="11" placeholder="01081026001" aria-label="Paste complete waypoint"></label><button type="button" id="entry-paste-apply" class="btn btn-sm btn-light">Apply waypoint</button><small id="entry-paste-rule"></small></div></details>
        <div class="entry-save-actions"><button type="submit" class="btn btn-primary" id="entry-save-next">Save &amp; Add Next</button><button type="button" class="btn btn-light" id="entry-save-draft">Save Draft</button><button type="button" class="btn btn-light" id="entry-row-new">New row / cancel edit</button><small id="entry-tab-help">Tab follows PDF order; calculated phase is skipped. Ctrl+Enter: save &amp; next · Ctrl+S: save draft. Drafts may contain incomplete pairs.</small></div></form>
    </section>@endif
    <section class="card" id="entry-saved"><div class="row-card-head"><h2>Saved rows for active transformer</h2><span id="entry-saved-count"></span></div><div id="entry-saved-rows"></div><div id="entry-legacy-records"></div><a class="btn btn-light" data-entry-leave id="entry-review-link" href="{{ route('mdb-workflow.review', $batch) }}">Review saved survey</a></section>
    @if($batch->staged_workflow && in_array($batch->survey_status, ['entry','returned'], true))
    @can('mdb-workflow.submit')<section class="card"><form id="entry-submit-form" method="POST" action="{{ route('mdb-workflow.survey.decision', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}" data-entry-revision><input type="hidden" name="action" value="submit"><button class="btn btn-primary">Submit for Verification</button><p class="mdb-note">Save the header and rows first. Submission locks the survey for the Data Verifier.</p></form></section>@endcan
    @elseif($batch->staged_workflow)<p class="mdb-note">Survey {{ str($batch->survey_status)->replace('_', ' ') }}. Entry is locked until the verifier returns it for correction.</p>@endif
    <details class="card entry-source-files"><summary>Upload PDF + GPX / source processing</summary><div id="entry-sources-list"></div>
        @if($canUpload)<form method="POST" action="{{ route('mdb-workflow.sources.store', $batch) }}" enctype="multipart/form-data">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}" data-entry-revision><div class="entry-fields"><label>Add PDF or GPX<input name="file" type="file" required accept=".pdf,.gpx"></label></div><div class="mdb-form-actions"><button class="btn btn-light">Upload source</button></div></form>@endif
        <a href="{{ route('mdb-workflow.advanced', $batch) }}#sources">Source versions and Google Drive import</a>
    </details><datalist id="entry-waypoint-options"></datalist>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}"><link rel="stylesheet" href="{{ asset('css/mdb-entry.css').'?v='.filemtime(public_path('css/mdb-entry.css')) }}">@endpush
@push('scripts')
<script>window.mdbEntryBoot = {{ \Illuminate\Support\Js::from($editorData + ['base_url' => route('mdb-workflow.show', $batch), 'settings_url' => route('mdb-workflow.config', $batch->project_id), 'pdfjs_url' => asset('vendor/pdfjs/build/pdf.min.mjs'), 'pdfjs_worker' => asset('vendor/pdfjs/build/pdf.worker.min.mjs'), 'pdfjs_assets' => asset('vendor/pdfjs').'/', 'storage_key' => 'mdb-entry:'.auth()->id().':'.$batch->id, 'can_edit' => $canEdit, 'active_transformer_id' => (int) request('transformer_id')]) }};</script><script src="{{ asset('js/mdb-entry.js').'?v='.filemtime(public_path('js/mdb-entry.js')) }}" type="module"></script>
@endpush
