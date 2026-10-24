@extends('layouts.app')
@section('title', 'GPX Length Calculator & KMZ Export')
@push('styles')<link rel="stylesheet" href="{{ asset('css/gpx-calculator.css') }}">@endpush
@section('content')
<div class="page-head"><div><h1>GPX Length Calculator &amp; KMZ Export</h1><p>Measure surveyed routes and confirmed support spans, with optional elevation and sag.</p></div></div>
<form class="card gpx-panel" method="POST" action="{{ route('gpx-calculator.verified') }}">
    @csrf
    <h2>Calculate from a verified transformer</h2>
    <p>Select an approved survey and transformer. Stored coordinates and S/E connections are loaded automatically; no files are needed. Elevation and sag can be enabled on the results page.</p>
    <label>Project / feeder / approved survey<select name="batch_id" id="verified-batch" required><option value="">Select approved survey</option>
        @foreach($verifiedBatches as $verifiedBatch)
            @if($verifiedBatch->approvedRevision?->number === $verifiedBatch->revision)
            <option value="{{ $verifiedBatch->id }}">{{ $verifiedBatch->project?->name }} / {{ $verifiedBatch->feeder?->feeder_code }} {{ $verifiedBatch->feeder?->feeder_name }} / Batch {{ $verifiedBatch->id }} / Revision {{ $verifiedBatch->revision }}</option>
            @endif
        @endforeach
    </select></label>
    <label>Verified transformer<select name="transformer_id" id="verified-transformer" required><option value="">Select transformer</option>
        @foreach($verifiedBatches as $verifiedBatch)
            @if($verifiedBatch->approvedRevision?->number === $verifiedBatch->revision)
                @foreach($verifiedBatch->approvedRevision->snapshot['transformers'] ?? [] as $verifiedTransformer)
                <option value="{{ $verifiedTransformer['id'] }}" data-batch="{{ $verifiedBatch->id }}" hidden disabled>{{ $verifiedTransformer['code'] }} ({{ $verifiedTransformer['capacity_kva'] ?? '?' }} kVA)</option>
                @endforeach
            @endif
        @endforeach
    </select></label>
    <button class="btn btn-primary" type="submit">Calculate verified transformer length</button>
    @if($verifiedBatches->isEmpty())<p class="muted">No accessible approved surveys are available. Complete survey verification first.</p>@endif
</form>
<script>
document.addEventListener('DOMContentLoaded',()=>{const batch=document.getElementById('verified-batch'),transformer=document.getElementById('verified-transformer');batch.addEventListener('change',()=>{transformer.value='';for(const option of transformer.options){if(option.dataset.batch){option.hidden=option.dataset.batch!==batch.value;option.disabled=option.hidden;}}});});
</script>
<form class="card gpx-panel" method="POST" action="{{ route('gpx-calculator.store') }}" enctype="multipart/form-data">
    @csrf
    <h2>Upload a GPX file</h2>
    <label>GPX file (up to 10 MB)<input type="file" name="gpx" accept=".gpx,application/gpx+xml,application/xml,text/xml" required></label>
    <label>Survey JSON (optional, up to 30 MB)<input type="file" name="survey_json" accept=".json,application/json"></label>
    <label>Transformer code (required if JSON contains multiple transformers)<input name="transformer_code" value="{{ old('transformer_code') }}" maxlength="100"></label>
    <p class="muted">With survey JSON, S/E pairs define the spans and branches. Only the selected transformer?s GPX waypoints are included. Disconnected or identical S/E entries must be corrected in data entry before importing. Confirm the imported connections before KMZ export.</p>
    <p class="muted">Routes and track segments retain their order and boundaries. Standalone waypoints need explicit span sequences. Elevation and sag are both off for every new upload.</p>
    @include('gpx-calculator.association')
    <button class="btn btn-primary" type="submit">Upload and inspect points</button>
</form>
<section class="card gpx-panel"><h2>Saved calculations</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>File</th><th>Project / feeder</th><th>Included spans</th><th>Final length</th><th>Updated</th></tr></thead><tbody>
    @forelse($calculations as $record)
        <tr><td><a href="{{ route('gpx-calculator.show', $record) }}">{{ $record->file_name }}</a></td><td>{{ $record->project?->code ?? 'Unlinked' }} / {{ $record->feeder?->feeder_code ?? '—' }}</td><td>{{ $record->result['valid_spans'] }} / {{ $record->result['span_count'] }}</td><td>{{ $record->result['final_m'] === null ? 'Needs review' : number_format($record->result['final_m'], 3).' m' }}</td><td>{{ $record->updated_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</td></tr>
    @empty<tr><td colspan="5">Upload a GPX to start a calculation.</td></tr>@endforelse
    </tbody></table></div>{{ $calculations->links() }}
</section>
@endsection
@push('scripts')<script src="{{ asset('js/gpx-calculator.js') }}" defer></script>@endpush
