@if(auth()->user()->canViewConsumerServiceCables())
<section class="card" id="entry-service-cables">
    <h2>Consumer service cables <span id="service-cable-count"></span></h2>
    <p class="mdb-note">Each meter describes a consumer already counted at an E pole. It does not increase the consumer total. Select the shared E pole once, then save its meters one by one. The next waypoint is suggested automatically; confirm it against the survey sheet and imported GPX.</p>
    <label class="mdb-check"><input id="service-cables-enabled" type="checkbox"> Meter/service-cable waypoints available for this transformer</label>
    <p id="service-cable-status" role="status" aria-live="polite"></p>
    <div id="service-cable-content" hidden>
        <details><summary>Pole coverage / missing meters</summary><div id="service-cable-coverage"></div></details>
<div class="service-cable-tools"><label>Search cables<input type="search" id="service-cable-search" placeholder="Waypoint, meter, reference or category"></label><label>E pole<select id="service-cable-filter-pole"><option value="">All poles</option></select></label><label>Records<select id="service-cable-filter-status"><option value="">All records</option><option value="incomplete">Missing meter/reference or phase</option></select></label>
@if($canEdit && auth()->user()->canEnterConsumerServiceCables())<button type="button" class="btn btn-primary" id="service-cable-add">+ Add cable</button>@endif</div>
        <div id="service-cable-list" class="table-wrap"></div><div class="actions service-cable-pagination"><button type="button" class="btn btn-light" id="service-cable-prev">Previous</button><span id="service-cable-page" role="status"></span><button type="button" class="btn btn-light" id="service-cable-next">Next</button></div>
        @if($canEdit && auth()->user()->canEnterConsumerServiceCables())
        <details id="service-cable-editor"><summary>Add / edit meter waypoint</summary>
        <form id="service-cable-form" autocomplete="off"><div class="actions"><h3 id="service-cable-editor-title">Add service cable</h3><button type="button" class="btn btn-light" id="service-cable-close">Close</button></div>
            <div class="entry-fields">
                <label>Starting E pole<select name="entry_row_id" required></select></label>
                <label>Consumer category<select name="category" required>@foreach(array_diff_key(\App\Services\Mdb\EntryLookups::CONSUMERS, ['pv' => true]) as $key => $label)<option value="{{ $key }}">{{ strtoupper($key) }} — {{ $label }}</option>@endforeach</select></label>
                <label>Consumer number within category<input name="consumer_number" type="number" required min="1" step="1"><small>For example, RS consumer 1 of the five RS consumers recorded at this E pole.</small></label>
                <label>Meter group<input name="group_number" required inputmode="numeric" pattern="[0-9]{2}" maxlength="2" placeholder="02"></label>
                <label>Meter date (DDMMYY)<input name="row_date" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="081026"></label>
                <label>Meter waypoint (next number suggested)<input name="waypoint_reference" required inputmode="numeric" pattern="[0-9]{3}" maxlength="3" placeholder="026"></label>
                <label>Meter GPX source<select name="gpx_source_id" required></select></label>
                <label>Exact meter waypoint<select name="meter_waypoint_id" required></select></label>
                <label>Complete meter identity<input name="composite_identifier" readonly tabindex="-1" placeholder="GGDDMMYYWWW"></label>
                <label>Consumer/reference number<input name="consumer_reference" maxlength="255"></label>
                <label>Meter number<input name="meter_number" maxlength="255"></label>
                <label>Recorded phase<select name="recorded_phase"><option value="">Not recorded</option><option>R</option><option>Y</option><option>B</option><option value="RYB">Three phase (RYB)</option></select></label>
                <label>Service cable<input name="conductor_code" maxlength="255"><small>Single phase: PVC 7/0.052. Three phase: PVC 19/0.052. Correct this if the survey records another cable; export requires an approved library code.</small></label>
                <label>Measured cable length (m)<input name="measured_length_m" type="number" min="0.001" max="100000" step="any"><small>Without a measured length, export uses the straight distance between recorded waypoints, labelled as an estimate.</small></label>
                <label>Measured length evidence<input name="length_evidence" maxlength="4000"></label>
                <label>Source PDF<select name="source_pdf_id" required></select></label>
                <label>PDF page<input name="source_page" required type="number" min="1" max="10000" step="1"></label>
                <label>PDF row / reference<input name="source_row" maxlength="255"></label>
                <label>Remarks<textarea name="remarks" maxlength="4000" rows="2"></textarea></label>
            </div>
            <button class="btn btn-light" type="button" id="service-cable-copy">Copy previous phase / cable type</button><button class="btn btn-light" type="button" id="service-cable-pole-location">Use meter waypoint at E coordinates</button>
            <p class="mdb-note">Pole-mounted meters each keep their own waypoint number, even when their coordinates match E.</p>
            <p id="service-cable-coordinates" class="mdb-note"></p>
            <div class="actions service-cable-save-actions"><span id="service-cable-save-status" role="status" aria-live="polite"></span><button class="btn btn-primary" type="submit">Save &amp; add next meter</button><button class="btn btn-light" type="submit" value="stay" id="service-cable-save-stay">Save</button><button class="btn btn-light" type="button" id="service-cable-cancel">New / cancel edit</button></div>
        </form></details>
        @endif
    </div>
</section>
@endif
