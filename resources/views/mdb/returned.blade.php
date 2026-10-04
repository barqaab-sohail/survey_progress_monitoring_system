@extends('layouts.app')
@section('title','Returned MDB Entries')
@section('content')
<div class="page-head"><div><h1>Returned MDB Entries</h1><p>Address the third-party reviewer's issue, then resubmit the corrected MDB item.</p></div><a class="btn btn-light" href="{{ route('mdb.index') }}">MDB history</a></div>
@forelse($items as $item)
<form class="card" method="POST" action="{{ route('mdb.resubmit', $item) }}">
@csrf @method('PUT')
<div class="page-head"><div><h3 style="margin:0">{{ $item->feeder->feeder_code }} · {{ $item->feeder->feeder_name }}</h3><p>Entry date {{ $item->entry->entry_date->format('d M Y') }}</p></div><span class="status status-returned">RETURNED</span></div>
<div class="alert alert-danger"><strong>Return reason:</strong> {{ $item->return_reason }}</div>
@php($restore = (string) old('correction_item_id') === (string) $item->id)
<input type="hidden" name="correction_item_id" value="{{ $item->id }}">
<div class="form-grid">
    <div class="field"><label>MDB files created</label><input type="number" inputmode="numeric" min="1" name="mdb_files_created" value="{{ $restore ? old('mdb_files_created', $item->mdb_files_created) : $item->mdb_files_created }}" required></div>
    <div class="field"><label>MDB Drive link</label><input type="url" name="drive_url" value="{{ $restore ? old('drive_url', $item->drive_url) : $item->drive_url }}" maxlength="2000"></div>
    <div class="field"><label>Remarks</label><input name="remarks" value="{{ $restore ? old('remarks', $item->remarks) : $item->remarks }}" maxlength="1000"></div>
</div>
<div class="form-footer"><span></span><button class="btn btn-primary" type="submit">Resubmit for verification</button></div>
</form>
@empty<div class="card empty">No MDB entries currently need correction.</div>@endforelse
{{ $items->links() }}
@endsection
