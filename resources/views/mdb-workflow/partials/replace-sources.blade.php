@if(auth()->user()->role?->value === 'super_admin')
<details class="card" id="replace-batch-sources" @if($errors->has('file') || $errors->has('reason')) open @endif>
    <summary>Replace PDF / GPX (Super Admin)</summary>
    <p class="mdb-note">Replace an unreadable PDF or incorrect GPX without deleting the batch or entered data. Upload the complete corrected PDF with the same page order. GPX replacements must retain existing waypoint names and distinguish repeated names by recording time. Previous files remain available below.</p>
    <p class="mdb-note">Saved entries and PDF annotations are kept. Review them against the replacement. Verification checks and previous MDB approval are cleared, and verified or submitted surveys return for correction.</p>
    @foreach($batch->sources as $source)
        <details>
            <summary>{{ strtoupper($source->kind) }}: {{ $source->original_name }} (v{{ $source->version }})</summary>
            @if(in_array($source->status, ['ready', 'failed'], true) && !$source->versions()->exists())
            <form method="POST" action="{{ route('mdb-workflow.sources.replace', [$batch, $source]) }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <input type="hidden" name="revision" value="{{ $batch->revision }}" data-entry-revision>
                <div class="mdb-fields">
                    <label>Replacement {{ strtoupper($source->kind) }}<input name="file" type="file" accept=".{{ $source->kind }}" required><small>{{ $source->kind === 'pdf' ? config('mdb_workflow.max_pdf_mb', 100) : 10 }} MB maximum. The file is checked before the replacement is applied.</small></label>
                    <label>Reason for replacement<input name="reason" required minlength="5" maxlength="2000" placeholder="For example: page 4 was unreadable"></label>
                </div>
                <div class="mdb-form-actions"><button class="btn btn-primary" type="submit">Replace {{ strtoupper($source->kind) }} and keep entered data</button></div>
            </form>
            @else
                <p class="mdb-note">Wait for processing to finish, or select the latest source version.</p>
            @endif
            @if(!empty($source->metadata['replacement_history']))
                <p><strong>Previous versions</strong></p>
                <ul>
                @foreach(array_reverse($source->metadata['replacement_history']) as $version)
                    <li><a href="{{ route('mdb-workflow.source', [$batch, $source, 'version' => $version['version'], 'download' => 1]) }}">{{ $version['original_name'] }} (v{{ $version['version'] }})</a> — {{ $version['reason'] }}</li>
                @endforeach
                </ul>
            @endif
        </details>
    @endforeach
</details>
@endif
