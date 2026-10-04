@extends('layouts.app')
@section('title', isset($entry) ? 'Edit Daily MDB Creation' : 'Add Daily MDB Creation')
@section('content')
<div class="page-head"><div><h1>{{ isset($entry) ? 'Edit Daily MDB Creation' : 'Daily MDB Creation' }}</h1><p>{{ isset($entry) ? 'Correct this entry before third-party review. Original feeder rows are retained.' : 'Only verified survey capacity is available. Saved MDB entries go directly to third-party verification.' }}</p></div><div class="actions"><a class="btn btn-light" href="{{ route('transformers.index') }}">Transformer GIS Data</a><a class="btn btn-light" href="{{ route('mdb.index') }}">View history</a></div></div>
<form class="card" method="POST" action="{{ isset($entry) ? route('mdb.update', $entry) : route('mdb.store') }}">
@csrf
@isset($entry) @method('PUT') @endisset
<div class="form-grid">
    <div class="field"><label>Date</label><input type="date" name="entry_date" max="{{ today()->toDateString() }}" value="{{ old('entry_date', isset($entry) ? $entry->entry_date->toDateString() : today()->toDateString()) }}" required></div>
    <div class="field"><label>Overall remarks</label><input name="remarks" value="{{ old('remarks', $entry->remarks ?? '') }}" maxlength="2000"></div>
</div>
<div class="section-title"><h2>MDB files created</h2>@if(!isset($entry))<button class="btn btn-sm btn-light" type="button" data-add-row>+ Add feeder</button>@endif</div>
<div data-row-list>
@foreach(old('items', isset($entry) ? $entry->items->toArray() : [[]]) as $item)
@include('mdb.partials.feeder-row', ['index' => $loop->index, 'item' => $item])
@endforeach
</div>
<div class="form-footer"><strong class="form-total">Total for entry: <span data-total>0</span></strong><button class="btn btn-primary">{{ isset($entry) ? 'Save changes' : 'Save MDB progress' }}</button></div>
</form>
<template id="row-template">@include('mdb.partials.feeder-row', ['index' => 0, 'item' => []])</template>
@endsection
@push('scripts')@include('components.entry-repeater-script')@endpush
