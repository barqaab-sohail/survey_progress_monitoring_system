@if($batch->staged_workflow)
<section class="card"><h2>Survey workflow</h2>
    <p>Status: <strong>{{ str($batch->survey_status)->replace('_', ' ')->title() }}</strong></p>
    @if($batch->survey_remarks)<p><strong>Correction comments:</strong> {{ $batch->survey_remarks }}</p>@endif
    @if(in_array($batch->survey_status, ['entry', 'returned'], true))
        @can('mdb-workflow.submit')<form method="POST" action="{{ route('mdb-workflow.survey.decision', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}"><input type="hidden" name="action" value="submit"><button class="btn btn-primary" @disabled(count($entryIssues))>Submit for Verification</button><p class="mdb-note">Submission locks the survey. Resolve incomplete entries before submitting.</p></form>@endcan
    @elseif($batch->survey_status === 'awaiting_verification')
        <p class="mdb-note">Submitted data is locked while the Data Verifier reviews it.</p>
        @if($canVerify)<form method="POST" action="{{ route('mdb-workflow.survey.decision', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}"><label>Approval / correction comments<textarea name="remarks" maxlength="10000" rows="3"></textarea></label><div class="mdb-form-actions"><button name="action" value="approve" class="btn btn-primary">Approve</button><button name="action" value="return" class="btn btn-light">Return for Correction</button></div><p class="mdb-note">Correction comments are required when returning the survey. Approval freezes the data for MDB creation.</p></form>@endif
    @elseif($batch->survey_status === 'approved')
        <p class="mdb-note">The approved survey is locked. MDB files use the frozen approved revision.</p>
    @endif
    <p class="mdb-note">Operator: {{ $batch->entryOperator?->name ?? 'Pending' }} · Submitted: {{ $batch->entry_completed_at ? $batch->entry_completed_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT' : 'Pending' }} · Verifier: {{ $batch->surveyVerifier?->name ?? 'Pending' }} · Decision: {{ $batch->survey_verified_at ? $batch->survey_verified_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT' : 'Pending' }}</p>
</section>
@endif
