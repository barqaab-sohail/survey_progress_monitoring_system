@php
    $corrections = $batch->entryCorrections()->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('id')->get();
    $openCorrections = $corrections->where('status', 'open')->count();
    $allowReport = $allowReport ?? false;
@endphp
<details class="entry-corrections" id="entry-corrections" data-admin="{{ auth()->user()->hasRole('super_admin') ? '1' : '0' }}">
    <summary class="btn btn-sm btn-light">PDF corrections / comments <span id="entry-correction-count">({{ $openCorrections }} open)</span></summary>
    <div class="entry-corrections-body">
    <p>Record why entry stopped so Super Admin can correct the PDF. Other transformer drafts can still be entered. Open corrections must be resolved before the batch is submitted.</p>
    @if($allowReport)
    <details id="entry-correction-editor"><summary>Record why entry stopped</summary>
        <form id="entry-correction-form" class="entry-correction-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="transformer_id">
            <div class="entry-fields entry-correction-location">
                <label>Transformer reference<input name="transformer_reference" required maxlength="255" placeholder="Transformer code or reference from PDF"></label>
                <label>PDF<select name="source_file_id" required>@foreach($batch->sources->where('kind', 'pdf') as $pdf)<option value="{{ $pdf->id }}">{{ $pdf->original_name }}</option>@endforeach</select></label>
                <label>PDF page<input name="source_page" required type="number" min="1" max="10000"></label>
                <label><span class="entry-correction-label-row">Row / pole / waypoint <span class="entry-correction-optional">Optional</span></span><input name="source_row" maxlength="100" placeholder="e.g. Pole 025"></label>
            </div>
            <div class="entry-correction-content">
                <label class="entry-correction-reason"><span>Why did entry stop? <span class="entry-required" aria-hidden="true">*</span></span><textarea name="comment" required minlength="5" maxlength="4000" rows="4" placeholder="Describe what is missing or incorrect, and which correction is needed."></textarea></label>
                <div class="entry-correction-attachment">
                    <label class="entry-correction-upload"><span>Screenshot <span class="entry-correction-optional">Optional</span></span><input type="file" name="screenshot" accept="image/png,image/jpeg,image/webp" aria-describedby="entry-correction-upload-hint entry-correction-screenshot-help"><small id="entry-correction-upload-hint">Help Super Admin see the issue.<br>PNG, JPG or WebP &middot; Up to 5 MB</small></label>
                    <p id="entry-correction-screenshot-help" class="mdb-note"></p>
                    <img id="entry-correction-preview" class="entry-correction-preview" alt="Selected error screenshot" hidden>
                    <button type="button" class="btn btn-sm btn-light" id="entry-correction-remove-screenshot" hidden>Remove screenshot</button>
                </div>
            </div>
            <div class="entry-correction-footer">
                <p class="mdb-note">Comments are saved separately.<br>Use <strong>Save Draft</strong> to keep incomplete entry before switching.</p>
                <div class="actions"><button type="submit" class="btn btn-primary">Save comment</button><button type="submit" value="next" class="btn btn-light" title="Save this comment and choose another transformer">Save &amp; next transformer <span aria-hidden="true">&rarr;</span></button></div>
            </div>
        </form>
    </details>
    @endif
    <p id="entry-correction-status" role="status" aria-live="polite"></p>
    <details id="entry-correction-history" @if($openCorrections) open @endif><summary>Operator comments and resolutions</summary>
        <div id="entry-correction-list">
        @forelse($corrections as $correction)
            <article class="entry-correction-item">
                <strong>{{ $correction->status === 'open' ? 'Awaiting PDF correction' : 'Resolved' }} · {{ $correction->transformer_reference }}</strong>
                <p>{{ $correction->pdf_name }} (v{{ $correction->pdf_version }}), page {{ $correction->source_page }}@if($correction->source_row) · {{ $correction->source_row }}@endif</p>
                <p class="entry-correction-comment">{{ $correction->comment }}</p>
                @if($correction->screenshot_url)<p><a href="{{ $correction->screenshot_url }}" target="_blank" rel="noopener">View error screenshot</a></p>@endif
                <small>Reported by {{ $correction->reporter_name }} · {{ $correction->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }}</small>
                @if($correction->status === 'resolved')
                    <p class="entry-correction-comment"><strong>Resolution:</strong> {{ $correction->resolution }}</p><small>{{ $correction->resolver_name }} · {{ $correction->resolved_at?->timezone('Asia/Karachi')->format('d M Y H:i') }}</small>
                @elseif(auth()->user()->hasRole('super_admin'))
                    <form method="POST" action="{{ route('mdb-workflow.entry-corrections.resolve', [$batch, $correction]) }}" data-resolve-correction="{{ $correction->id }}">@csrf @method('PUT')
                        <label>How was it resolved?<textarea name="resolution" required minlength="5" maxlength="4000" rows="2" placeholder="Record the corrected PDF or information provided."></textarea></label>
                        <button class="btn btn-success" type="submit">Mark resolved</button>
                    </form>
                @endif
            </article>
        @empty<p>No PDF corrections reported.</p>@endforelse
        </div>
    </details>
    <button type="button" class="btn btn-sm btn-light" id="entry-correction-refresh" hidden>Refresh comments</button>
    </div>
</details>
