@extends('layouts.app')
@section('title','Returned Survey Entries')
@section('content')
<div class="page-head"><div><h1>Returned for Correction</h1><p>Address the MDB user's issue, then resubmit the corrected survey item.</p></div></div>
@forelse($items as $item)
<form class="card" method="POST" action="{{ route('survey.resubmit', $item) }}">
@csrf @method('PUT')
<div class="page-head"><div><h3 style="margin:0">{{ $item->feeder->feeder_code }} · {{ $item->feeder->feeder_name }}</h3><p>Entry date {{ $item->entry->entry_date->format('d M Y') }}</p></div><span class="status status-returned">RETURNED</span></div>
<div class="alert alert-danger"><strong>Return reason:</strong> {{ $item->return_reason }}</div>
@php($restore = (string) old('correction_item_id') === (string) $item->id)
<input type="hidden" name="correction_item_id" value="{{ $item->id }}">
<div class="form-grid">
    <div class="field"><label>Correct quantity</label><input type="number" inputmode="numeric" min="1" name="transformers_surveyed" value="{{ $restore ? old('transformers_surveyed', $item->transformers_surveyed) : $item->transformers_surveyed }}" required></div>
    <div class="field"><label>Survey Google Drive URL <small>(required)</small></label><input type="url" name="drive_url" value="{{ $restore ? old('drive_url', $item->drive_url) : $item->drive_url }}" placeholder="https://drive.google.com/drive/folders/..." maxlength="2000" required></div>
    <div class="field"><label>Remarks</label><input name="remarks" value="{{ $restore ? old('remarks', $item->remarks) : $item->remarks }}"></div>
</div>
<div class="form-footer"><span></span><button class="btn btn-primary" type="submit">Resubmit</button></div>
</form>
@empty<div class="card empty">No entries currently need correction.</div>@endforelse
{{ $items->links() }}
@endsection
