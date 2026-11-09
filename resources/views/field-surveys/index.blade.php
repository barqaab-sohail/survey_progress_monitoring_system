@extends('layouts.app')
@section('title', 'LT Transformer Surveys')
@section('content')
<div class="page-head"><div><h1>LT Transformer Surveys</h1><p>LT transformer survey sheets collected in the mobile app. HT verification is tracked separately.</p></div></div>
<form class="card" method="GET" action="{{ route('field-surveys.index') }}">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end">
        <label>Transformer or feeder<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Search by code or name"></label>
        <label>Status<select name="status"><option value="">All records</option value="draft" @selected(($filters['status'] ?? '') === 'draft')>Draft</option><option value="submitted" @selected(($filters['status'] ?? '') === 'submitted')>Submitted</option></select></label>
        <div class="actions"><button class="btn btn-primary">Search</button><a class="btn btn-light" href="{{ route('field-surveys.index') }}">Clear</a></div>
    </div>
</form>
@forelse($surveys as $survey)
<article class="card">
    <div class="page-head"><div><h2 style="margin:0 0 6px"><a href="{{ route('field-surveys.show', $survey->client_uuid) }}">{{ $survey->transformer_code ?: 'Untitled draft' }}</a></h2><p>{{ $survey->reference_snapshot['feeder_code'] ?? $survey->feeder->feeder_code }} &middot; {{ $survey->reference_snapshot['feeder_name'] ?? $survey->feeder->feeder_name }}</p></div><span class="status">{{ ucfirst($survey->status) }}</span></div>
    <p>{{ $survey->survey_date->format('d M Y') }} &middot; {{ $survey->team->name }} &middot; {{ $survey->collector->name }}</p>
    <p>{{ count($survey->rows) }} survey rows &middot; {{ count($survey->solar) }} solar entries &middot; {{ $survey->attachments_count }} attachments</p>
    <p><strong>Survey name:</strong> {{ $survey->survey_name }}</p>
    @if($survey->htUpdate)<p><strong>HT Network Update:</strong> {{ ucfirst($survey->htUpdate->status) }} · <a href="{{ route('ht-updates.show', $survey->htUpdate) }}">View HT connection</a></p>@endif
    <a class="btn btn-light" href="{{ route('field-surveys.show', $survey->client_uuid) }}">Open survey sheet</a>
    @if(app(\App\Services\MobileGpxWorkflow::class)->canLink(auth()->user()))<a class="btn btn-primary" href="{{ route('mobile-gpx.show', $survey->client_uuid) }}">Link GPX / JSON</a>@endif
    @include('field-surveys.actions')
</article>
@empty
<div class="card"><h2>No field surveys found</h2><p>Synced survey sheets appear here. Field collection is available for active survey teams with assigned feeders.</p></div>
@endforelse
{{ $surveys->links() }}
@endsection
