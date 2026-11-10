@php($registry = app(\App\Services\TransformerRegistryService::class))
<section class="card" id="reference-verification">
    <h2>Reference verification</h2>
    <p><strong>{{ $transformer->verification_label }}</strong></p>
    <dl class="detail-list">
        <div><dt>Permanent transformer ID</dt><dd>{{ $transformer->id }}</dd></div>
        <div><dt>Original client code</dt><dd>{{ $transformer->source_transformer_code ?? $transformer->transformer_code }}</dd></div>
        <div><dt>Source feature ID</dt><dd>{{ $transformer->source_feature_id ?? 'Not supplied' }}</dd></div>
        <div><dt>Temporary working reference</dt><dd>{{ $transformer->temporary_reference ?? 'Not required' }}</dd></div>
        <div><dt>Original source capacity</dt><dd>{{ $transformer->source_capacity_kva ?? 'Not supplied' }}{{ $transformer->source_capacity_kva !== null ? ' kVA' : '' }}</dd></div>
    </dl>
    @if($transformer->hasPendingVerification())<p class="alert alert-warning">Surveys and evidence collection can continue. Final engineering approval and MDB generation for this transformer require confirmed identity and positive capacity.</p>@endif
    @if(auth()->user()->hasRole('super_admin'))
    <details @if($transformer->code_verification_status === 'pending_client') open @endif><summary>Record client-confirmed transformer code</summary>
        <form method="POST" action="{{ route('transformers.code.confirm', $transformer) }}">@csrf
            <input type="hidden" name="verification_revision" value="{{ $transformer->verification_revision }}">
            <div class="form-grid">
                <label>Official code confirmed by client<input name="transformer_code" required maxlength="100" value="{{ old('transformer_code', $transformer->code_verification_status === 'confirmed' ? $transformer->transformer_code : '') }}"></label>
                <label>Client confirmation reference<input name="client_reference" required minlength="5" maxlength="4000" placeholder="Client letter/email reference and date"></label>
                <label>Reason / confirmation notes<textarea name="reason" required minlength="5" maxlength="4000" rows="2"></textarea></label>
            </div><p>The permanent ID, field surveys and photos are retained. Linked PDF work is reopened for verification; frozen history is preserved.</p>
            <button class="btn btn-primary">Save client confirmation</button>
        </form>
    </details>
    @endif
    @if($registry->canObserve(auth()->user(), $transformer))
    <details @if($transformer->capacity_verification_status === 'pending_field') open @endif><summary>Record capacity from field visit</summary>
        <form method="POST" enctype="multipart/form-data" action="{{ route('transformers.capacity.observe', $transformer) }}">@csrf
            <input type="hidden" name="verification_revision" value="{{ $transformer->verification_revision }}">
            <div class="form-grid">
                <label>Observed capacity (kVA)<input name="capacity_kva" type="number" required min="0.01" max="99999999" step="0.01"></label>
                <label>Nameplate / field photograph<input name="evidence_photo" type="file" accept="image/jpeg,image/png,image/webp" required></label>
                <label>Visit date and supporting notes<textarea name="evidence_note" required minlength="5" maxlength="4000" rows="2"></textarea></label>
            </div><button class="btn btn-primary">Submit capacity for verification</button>
        </form>
        <p>Mobile surveys also submit a capacity observation automatically when an unknown-capacity transformer is submitted with a positive measured rating. Upload its supporting row photograph from the phone.</p>
    </details>
    @endif
</section>
<section class="card"><h2>Field capacity observations</h2>
@forelse($transformer->capacityObservations->sortByDesc('id') as $observation)
    <article class="card"><h3>{{ $observation->capacity_kva }} kVA — {{ ucfirst($observation->status) }}</h3>
        <p>{{ $observation->observer?->name ?? 'Former user' }} · {{ $observation->created_at }} · {{ $observation->evidence_note }}</p>
        @if($observation->evidence_path)<a class="btn btn-light" target="_blank" rel="noopener" href="{{ route('transformers.capacity.evidence', [$transformer, $observation]) }}">View field photograph</a>@endif
        @foreach($observation->survey?->attachments ?? [] as $photo)
            @if(str_starts_with($photo->mime_type, 'image/'))<a class="btn btn-light" target="_blank" rel="noopener" href="{{ route('transformers.capacity.survey-evidence', [$transformer, $observation, $photo->id]) }}">View survey photograph #{{ $photo->id }}</a>@endif
        @endforeach
        @if($observation->reviewed_at)<p>Reviewed by {{ $observation->reviewer?->name ?? 'Former user' }} · {{ $observation->reviewed_at }} · {{ $observation->review_note }}</p>@endif
        @if($observation->status === 'pending' && $registry->canReview(auth()->user(), $transformer) && $observation->observed_by !== auth()->id())
            <form method="POST" action="{{ route('transformers.capacity.review', [$transformer, $observation]) }}">@csrf
                <input type="hidden" name="verification_revision" value="{{ $transformer->verification_revision }}">
                <label>Verification decision<select name="decision" required><option value="approved">Approve observed capacity</option><option value="rejected">Reject / request another observation</option></select></label>
                <label>Review notes<textarea name="review_note" required minlength="5" maxlength="4000" rows="2"></textarea></label>
                <button class="btn btn-primary">Save verification decision</button>
            </form>
        @endif
    </article>
@empty<p>No capacity observations recorded.</p>@endforelse
</section>
<section class="card"><h2>Reference correction history</h2>
@forelse($transformer->registryChanges->sortByDesc('id') as $change)
    <details><summary>{{ str_replace('_', ' ', ucfirst($change->kind)) }} · {{ $change->created_at }} · {{ $change->actor?->name ?? 'Former user' }}</summary>
        <p>{{ $change->reason }} · Evidence: {{ $change->evidence_reference }}</p>
        <p>Before: {{ json_encode($change->old_values, JSON_UNESCAPED_UNICODE) }}</p>
        <p>After: {{ json_encode($change->new_values, JSON_UNESCAPED_UNICODE) }}</p>
    </details>
@empty<p>No approved corrections yet. Original KMZ attributes are retained below.</p>@endforelse
</section>
