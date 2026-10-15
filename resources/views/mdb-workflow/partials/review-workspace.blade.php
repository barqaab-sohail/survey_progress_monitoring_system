@php
    $canReview = $canEdit || $canCorrect;
    $reviewState = app(\App\Services\Mdb\ReviewService::class)->state($batch, $active, auth()->id());
@endphp
<section class="card verification-workspace" id="verification-workspace" hidden>
    <div class="verification-heading"><div><h2>Entered survey - same layout as the PDF</h2><p class="mdb-note">Tick each value after checking it. A dash means not entered; zero means a recorded zero.</p></div><strong id="verification-progress" role="status" aria-live="polite"></strong></div>
    @if($canReview)<p class="verification-help">Compare the original PDF above with the entered sheet below. Click a saved value to correct it; tick its checkbox after checking it. Corrections require a reason.</p>@endif
    <p id="verification-message" role="status" aria-live="polite"></p>
    <div id="verification-data-issues" class="entry-error-summary" hidden></div>
    <div id="verification-errors" class="entry-error-summary" role="alert" hidden></div>
    @unless($canReview)<p class="mdb-note">Read-only preview. A Data Verifier can check and correct values while this survey is pending verification.</p>@endunless
    <details id="verification-header" open>
        <summary><span>Transformer header - paper form</span><strong id="verification-header-progress"></strong></summary>
        <div id="verification-header-values" class="verification-header-values paper-header"></div>
        <div class="verification-tools"><button type="button" class="btn btn-primary" id="verification-check-header" @disabled(!$canReview)>Mark header checked &amp; hide</button><button type="button" class="btn btn-light" id="verification-edit-header" @disabled(!$canReview)>Correct header</button><button type="button" class="btn btn-light" id="verification-hide-header">Hide header</button></div>
    </details>
    <div class="verification-pair-nav"><button type="button" class="btn btn-light" id="verification-previous">&larr; Previous pair</button><label>Pair<select id="verification-pair" aria-label="Choose S/E pair"></select></label><button type="button" class="btn btn-light" id="verification-next">Next pair &rarr;</button><button type="button" class="btn btn-light" id="verification-next-unchecked">Next unchecked pair</button><strong id="verification-pair-progress"></strong></div>
    <div id="verification-row-tools" class="verification-row-tools"></div>
    <p id="verification-sheet-page" class="paper-page-caption" role="status"></p>
    <p class="paper-legend">Click a value to edit. Tick its checkbox after checking. &mdash; = not entered; None = explicitly not present; 0 = recorded zero. Phase is derived from the conductors.</p>
    <div class="paper-sheet-scroll" tabindex="0" aria-label="Entered survey paper sheet; scroll horizontally on smaller screens">
        <div class="paper-sheet-body">
            <table class="verification-values paper-values"><colgroup><col style="width:28px"><col style="width:42px"><col style="width:96px"><col style="width:60px"><col style="width:42px">@for($i=0;$i<4;$i++)<col style="width:42px">@endfor<col style="width:44px"><col style="width:44px"><col style="width:62px">@for($i=0;$i<10;$i++)<col style="width:34px">@endfor<col style="width:30px"><col style="width:100px"><col style="width:60px"><col style="width:60px"><col style="width:160px"></colgroup><caption>Entered S/E rows for the displayed PDF page</caption><thead>
                <tr><th rowspan="2" scope="col">S/E</th><th rowspan="2" scope="col">Grp</th><th rowspan="2" scope="col">Date<br>DDMMYY</th><th rowspan="2" scope="col">GPS WP</th><th colspan="5" scope="colgroup">Conductor</th><th colspan="3" scope="colgroup">Equipment Type</th><th colspan="10" scope="colgroup">Consumer Type</th><th rowspan="2" scope="col">Int</th><th colspan="4" scope="colgroup">PV Solar / Net-Metering Data</th></tr>
                <tr><th>Phase</th><th>R</th><th>Y</th><th>B</th><th>Neutral</th><th>Type</th><th>Pole<br>class</th><th>Pole<br>height</th>@foreach(['RS','RL','SC','LC','SI','LI','PB','AG','ST','PV'] as $category)<th>{{ $category }}</th>@endforeach<th>Consumer<br>reference</th><th>Service<br>load (kW)</th><th>Solar<br>capacity (kW)</th><th>Remarks</th></tr>
            </thead><tbody id="verification-values"></tbody></table>
        </div>
    </div>
    <div class="paper-page-references" id="verification-sheet-references"></div>
    <span id="verification-s-page" hidden></span><span id="verification-e-page" hidden></span>
    <p id="verification-empty" class="mdb-note" hidden>No S/E rows have been entered for this transformer.</p>
    <div class="verification-tools"><button type="button" class="btn btn-light" id="verification-refresh">Refresh saved values</button><button type="button" class="btn btn-light" id="verification-table">Show full saved table</button><small>Review checks are saved for your account. Correcting a header or row clears its checks until you review the updated values.</small></div>
    <dialog id="verification-editor" class="verification-editor">
        <form id="verification-edit-form" novalidate>
            <h2 id="verification-editor-title"></h2><p class="mdb-note">Enter the values shown on the PDF. Changes are recorded in the action history.</p>
            <div id="verification-editor-errors" class="entry-error-summary" role="alert" hidden></div>
            <div id="verification-editor-fields" class="verification-editor-fields"></div>
            <label class="verification-reason">Reason for correction <span class="entry-required">*</span><textarea id="verification-correction-reason" required minlength="5" maxlength="4000" rows="2" placeholder="Explain the correction against the PDF"></textarea></label>
            <div class="verification-tools"><button type="submit" class="btn btn-primary" id="verification-save-correction">Save correction &amp; return</button><button type="button" class="btn btn-light" id="verification-cancel-correction">Cancel</button></div>
        </form>
    </dialog>
</section>
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-verification.css').'?v='.filemtime(public_path('css/mdb-verification.css')) }}"><link rel="stylesheet" href="{{ asset('css/mdb-verification-sheet.css').'?v='.filemtime(public_path('css/mdb-verification-sheet.css')) }}">@endpush
@push('scripts')
<script>window.mdbVerificationBoot = {{ \Illuminate\Support\Js::from($reviewState + ['can_correct'=>$canReview,'state_url'=>route('mdb-workflow.verification.state',[$batch,$active]),'check_url'=>route('mdb-workflow.verification.check',[$batch,$active]),'header_url'=>$canEdit ? route('mdb-workflow.entry.headers.update',[$batch,$active]) : route('mdb-workflow.verification.header',[$batch,$active]),'row_url'=>$canEdit ? route('mdb-workflow.show',$batch).'/transformers/'.$active->id.'/rows/' : route('mdb-workflow.verification.state',[$batch,$active]).'/rows/','source_urls'=>$batch->sources->where('kind','pdf')->mapWithKeys(fn($source)=>[$source->id=>route('mdb-workflow.source',[$batch,$source])])]) }};</script>
<script type="module" src="{{ asset('js/mdb-verification.js').'?v='.filemtime(public_path('js/mdb-verification.js')) }}"></script>
@endpush
