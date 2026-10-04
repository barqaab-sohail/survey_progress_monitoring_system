@extends('layouts.app')
@section('title', isset($entry) ? 'Edit Daily Survey' : 'Add Daily Survey')
@section('content')
<div class="page-head"><div><h1>{{ isset($entry) ? 'Edit Daily Survey Progress' : 'Add Daily Survey Progress' }}</h1><p>{{ $team->name }} · {{ isset($entry) ? 'Correct this entry before verification. Original feeder rows are retained.' : "enter only today's completed quantities." }}</p></div><div class="actions"><a class="btn btn-light" href="{{ route('transformers.index') }}">Transformer GIS Data</a><a class="btn btn-light" href="{{ route('survey.index') }}">View history</a></div></div>
@if(!isset($entry))
<form class="card" method="GET" action="{{ route('survey.create') }}">
<div class="form-grid"><div class="field"><label for="survey_team_id">Survey team</label><select id="survey_team_id" name="survey_team_id" required>
@foreach($availableTeams as $availableTeam)
<option value="{{ $availableTeam->id }}" @selected($availableTeam->id === $team->id)>{{ $availableTeam->code }} - {{ $availableTeam->name }} ({{ $availableTeam->project->name }})</option>
@endforeach
</select></div><div class="field" style="align-self:end"><button class="btn btn-light" type="submit">Load feeders</button></div></div>
</form>
@endif
@if($feeders->isEmpty())
<div class="card" role="alert"><h2>No feeders assigned</h2><p>Excel import adds feeder master data. An administrator must assign feeders to {{ $team->name }} in Teams &amp; Assignments before you can add survey progress.</p>@if(auth()->user()->hasRole(\App\Enums\UserRole::SuperAdmin->value))<a class="btn btn-primary" href="{{ route('admin.teams.index') }}">Assign survey feeders</a>@endif</div>
@else
@if($feeders->contains(fn ($feeder) => $feeder->baseline_pending || $feeder->total_transformers < 1))
<div class="card" role="alert"><p>Feeders marked "baseline pending" need verified transformer totals before survey progress can be submitted. Ask an administrator to update their totals in Feeder Master Data.</p></div>
@endif
<form class="card" method="POST" action="{{ isset($entry) ? route('survey.update', $entry) : route('survey.store') }}">@csrf
@isset($entry) @method('PUT') @endisset
@if(!isset($entry))<input type="hidden" name="survey_team_id" value="{{ $team->id }}">@endif
<div class="form-grid"><div class="field"><label for="entry_date">Date</label><input id="entry_date" type="date" name="entry_date" max="{{ today()->toDateString() }}" value="{{ old('entry_date', isset($entry) ? $entry->entry_date->toDateString() : today()->toDateString()) }}" required></div><div class="field"><label for="remarks">Overall remarks <small>(optional)</small></label><input id="remarks" name="remarks" value="{{ old('remarks', $entry->remarks ?? '') }}" maxlength="2000"></div></div>
<div class="section-title"><h2>Feeders surveyed</h2>@if(!isset($entry))<button class="btn btn-sm btn-light" type="button" data-add-row>+ Add feeder</button>@endif</div>
<div data-row-list>
@foreach(old('items', isset($entry) ? $entry->items->toArray() : [[]]) as $item)
@include('survey.partials.feeder-row', ['index' => $loop->index, 'item' => $item])
@endforeach
</div>
<div class="form-footer"><strong class="form-total">Total for entry: <span data-total>0</span></strong><button class="btn btn-primary" type="submit">{{ isset($entry) ? 'Save changes' : 'Submit for verification' }}</button></div></form>
<template id="row-template">@include('survey.partials.feeder-row', ['index' => 0, 'item' => []])</template>
@endif
@endsection
@if($feeders->isNotEmpty())
@push('scripts')@include('components.entry-repeater-script')@endpush
@endif
