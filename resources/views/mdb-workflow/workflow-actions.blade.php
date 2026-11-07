@if($batch->staged_workflow)
<section class="card mdb-workflow-actions"><h2>Survey workflow</h2>
    <p>Status: <strong>{{ $batch->workflow_label }}</strong></p>
    @if($batch->survey_remarks)<p><strong>Verification / correction comments:</strong> {{ $batch->survey_remarks }}</p>@endif
    @if(in_array($batch->survey_status, ['entry', 'returned'], true))
        @can('mdb-workflow.submit')<form method="POST" action="{{ route('mdb-workflow.survey.decision', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}"><input type="hidden" name="action" value="submit"><button class="btn btn-primary" @disabled($batch->transformers->isEmpty() || count($entryIssues))>Submit for Verification</button>@if($batch->transformers->isEmpty())<p class="mdb-note">No transformer has been saved in this batch yet. <a href="{{ route('mdb-workflow.show', $batch) }}">Open PDF Data Entry</a> to save a transformer header and its S/E rows first.</p>@else<p class="mdb-note">Submission locks the survey. Resolve incomplete entries before submitting.</p>@endif</form>@endcan
    @elseif($batch->survey_status === 'awaiting_verification')
        <p class="mdb-note">Submitted data is locked while the Data Verifier reviews it.</p>
        @if($canVerify)
        <form method="POST" action="{{ route('mdb-workflow.survey.decision', $batch) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $batch->revision }}">
            <label>Approval comments (optional)<textarea name="remarks" maxlength="10000" rows="3"></textarea></label>
            <div class="mdb-form-actions">
                <button name="action" value="approve" class="btn btn-primary">Approve</button>
                @unless(auth()->user()->hasRole('survey_data_verifier'))
                <button name="action" value="flag" class="btn btn-light">Flag Discrepancy</button>
                <button name="action" value="return" class="btn btn-light">Return for Correction</button>
                @endunless
            </div>
            <p class="mdb-note">Correct any errors in the entered survey above, then approve. Approval freezes the data for MDB creation.</p>
            @unless(auth()->user()->hasRole('survey_data_verifier'))<p class="mdb-note">Comments are required to flag a discrepancy or return the survey.</p>@endunless
        </form>
        @endif
    @elseif($batch->survey_status === 'approved')
        <p class="mdb-note">The verified survey is locked. MDB files use the frozen approved revision.</p>
        @can('mdb-workflow.edit')
        <form method="POST" action="{{ route('mdb-workflow.reopen', $batch) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $batch->revision }}">
            <label for="workflow-correction-reason">Reason for correction <span aria-hidden="true">*</span></label>
            <textarea id="workflow-correction-reason" name="remarks" required maxlength="10000" rows="4" placeholder="Describe the changes required in this survey">{{ old('remarks') }}</textarea>
            @error('remarks')<p class="mdb-inline-error">{{ $message }}</p>@enderror
            <div class="mdb-form-actions"><button class="btn btn-light" type="submit">Reopen for Correction</button></div>
            <p class="mdb-note">Reopening marks previous MDB files outdated and requires verification again.</p>
        </form>
        @endcan
    @endif
    <p class="mdb-note">Operator: {{ $batch->entryOperator?->name ?? 'Pending' }} · Submitted: {{ $batch->entry_completed_at ? $batch->entry_completed_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT' : 'Pending' }} · Verifier: {{ $batch->surveyVerifier?->name ?? 'Pending' }} · Decision: {{ $batch->survey_verified_at ? $batch->survey_verified_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT' : 'Pending' }}</p>
</section>
@endif
