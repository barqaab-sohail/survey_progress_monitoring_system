@extends('layouts.app')
@section('title', 'Automatic Survey Progress')
@section('content')
<div class="page-head"><div><h1>HAZECO Survey Progress</h1><p>Matched survey PDF and GPX files from Google Drive. Historical manual entries are retained separately.</p></div>
@if(auth()->user()->hasRole('super_admin') && $ready)<div class="actions"><form method="POST" action="{{ route('survey-progress.drive.connect') }}">@csrf<button class="btn btn-light" @disabled(!$configured)>{{ $connected ? 'Reconnect' : 'Connect' }} survey Drive</button></form><form method="POST" action="{{ route('survey-progress.sync') }}">@csrf<button class="btn btn-primary" @disabled(!$connected)>Synchronize now</button></form></div>@endif</div>
@unless($configured)<div class="alert alert-danger">Google OAuth client credentials are not configured for survey progress. An administrator must configure the client and callback before connecting Drive.</div>@endunless
@unless($ready)<div class="alert alert-danger">Survey progress tables are not installed. Apply the separate survey progress migration before synchronization.</div>@endunless
@unless($connected)<div class="card">Daily synchronization needs a separate read-only Google Drive connection with access to the parent survey folder.</div>@endunless
<div class="form-grid">
    <article class="card"><h3>Reported Surveyed Transformers</h3><strong>{{ $summary['surveyed'] }}</strong><p>Filename quantities; unique physical transformers are not independently verified. {{ $states->whereNotNull('synced_at')->count() }} of {{ $feeders->count() }} feeders have a successful scan.</p></article>
    <article class="card"><h3>Remaining transformers</h3><strong>{{ $summary['remaining'] }}</strong><p>Known baseline: {{ $summary['baseline'] }}. Overall progress: {{ $summary['percentage'] === null ? 'Baseline unavailable' : $summary['percentage'].'%' }}.</p></article>
    <article class="card"><h3>Confirmed LT length</h3><strong>{{ number_format($summary['confirmed_km'], 3) }} km</strong><p>{{ $summary['calculated_feeders'] }} feeders with current calculations. Stale and failed results excluded.</p></article>
    <article class="card"><h3>Unverified horizontal length</h3><strong>{{ number_format($summary['unverified_km'], 3) }} km</strong><p>Diagnostic 2D distances only. Missing endpoints have no distance estimate. These values are excluded from confirmed LT totals.</p></article>
</div>
<p>Counts reflect the latest successful scan for each feeder. Failed or unmapped feeders require review. Percentages use only available baselines; unsynchronized feeders have no reported count yet.</p>
<section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>Baseline</th><th>Reported surveyed / remaining / %</th><th>Drive scan</th><th>Confirmed LT km</th><th>Unverified 2D km / spans</th><th>Calculation</th><th>Actions</th></tr></thead><tbody>
@forelse($feeders as $feeder)
@php($state = $states->get($feeder->id))
@php($knownBaseline = !$feeder->baseline_pending && $feeder->total_transformers > 0)
<tr><td>{{ $feeder->feeder_code }}<small>{{ $feeder->feeder_name }}</small></td><td>{{ $knownBaseline ? $feeder->total_transformers : 'Unavailable' }}</td>
<td>{{ $state?->synced_at ? $state->surveyed_count : 'Not scanned' }} / {{ $knownBaseline && $state?->synced_at ? max(0, $feeder->total_transformers - $state->surveyed_count) : '—' }} / {{ $knownBaseline && $state?->synced_at ? round($state->surveyed_count / $feeder->total_transformers * 100, 1).'%' : '—' }}
@if($knownBaseline && ($state?->surveyed_count ?? 0) > $feeder->total_transformers)<small>Count exceeds baseline; review files or baseline.</small>@endif</td>
<td>{{ str($state?->sync_status ?? 'not_synced')->replace('_', ' ')->title() }}<small>{{ $state?->synced_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'Never' }}</small></td>
<td>{{ $state?->confirmed_km === null ? '—' : number_format((float) $state->confirmed_km, 3) }}@if(in_array($state?->length_status, ['stale','failed'], true))<small>Previous result; excluded from totals</small>@endif</td>
<td>{{ $state?->unverified_horizontal_km === null ? '—' : number_format((float) $state->unverified_horizontal_km, 3) }} / {{ $state?->unresolved_spans ?? '—' }}</td>
<td>{{ str($state?->length_status ?? 'not_calculated')->replace('_', ' ')->title() }}<small>{{ $state?->calculated_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'Never' }}</small></td>
<td>@if(auth()->user()->hasRole('super_admin') && $ready)
<a class="btn btn-sm btn-light" href="{{ route('survey-progress.transcriptions', $feeder) }}">Review PDF S/E records</a>
<form method="POST" action="{{ route('survey-progress.length', $feeder) }}">@csrf<button class="btn btn-sm btn-primary" @disabled(!$connected || !$state?->synced_at)>Calculate LT Length</button></form>
<details><summary>Folder mapping</summary><form method="POST" action="{{ route('survey-progress.mapping', $feeder) }}">@csrf @method('PUT')<label>Drive folder ID<input name="folder_id" value="{{ $state?->folder_id }}" required maxlength="200"></label><button class="btn btn-sm btn-light">Save mapping</button></form></details>
@endif
@if($state?->issues)<details><summary>Source validation ({{ count($state->issues) }})</summary><ul>@foreach($state->issues as $issue)<li>{{ str($issue['code'])->replace('_', ' ')->title() }} {{ $issue['file'] ?? $issue['survey'] ?? $issue['message'] ?? '' }}</li>@endforeach</ul></details>@endif
@if($state?->length_issues)<details><summary>Length validation ({{ count($state->length_issues) }})</summary><ul>@foreach($state->length_issues as $issue)<li>{{ str($issue['code'])->replace('_',' ')->title() }} {{ $issue['reference'] ?? $issue['survey'] ?? $issue['file'] ?? $issue['key'] ?? '' }} @if(isset($issue['reasons'])){{ implode(', ', $issue['reasons']) }}@endif</li>@endforeach</ul></details>@endif
@if($state?->aggregates)<details><summary>Reported counts by group and date</summary>@foreach($state->aggregates as $dimension=>$values)<p>{{ str($dimension)->replace('_',' ')->title() }}: @foreach($values as $key=>$quantity){{ $key }} = {{ $quantity }}; @endforeach</p>@endforeach</details>@endif</td></tr>
@empty<tr><td colspan="8">No assigned active feeders match the configured HAZECO project code.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card"><h2>Project reported counts by group and date</h2>@foreach(['by_group'=>'Survey group', 'by_date'=>'Survey date'] as $dimension=>$label)<h3>{{ $label }}</h3><div class="table-wrap"><table class="table"><thead><tr><th>{{ $label }}</th><th>Reported Surveyed Transformers</th></tr></thead><tbody>@foreach($summary[$dimension] as $key=>$quantity)<tr><td>{{ $key }}</td><td>{{ $quantity }}</td></tr>@endforeach</tbody></table></div>@endforeach</section>
<section class="card"><h2>Synchronization and calculation history</h2><div class="table-wrap"><table class="table"><thead><tr><th>Run</th><th>Requested by</th><th>Status</th><th>Started / finished (Karachi)</th><th>Validation</th></tr></thead><tbody>
@foreach($runs as $run)<tr><td>#{{ $run->id }} · {{ $run->type }}</td><td>{{ $run->requester?->name ?? 'Daily scheduler' }}</td><td>{{ str($run->status)->replace('_', ' ')->title() }}</td><td>{{ $run->started_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'Queued' }}<small>{{ $run->finished_at?->timezone('Asia/Karachi')->format('d M Y H:i') }}</small></td><td>{{ $run->error }}
@if($run->result && (auth()->user()->hasAnyRole(['super_admin','project_manager','management_viewer']) || $run->progress_feeder_id))
<details><summary>Validation results ({{ count($run->result['issues'] ?? []) }})</summary><ul>@foreach($run->result['issues'] ?? [] as $issue)<li>{{ str($issue['code'])->replace('_',' ')->title() }} · {{ $issue['survey'] ?? $issue['file'] ?? $issue['reference'] ?? $issue['name'] ?? $issue['feeder_id'] ?? '' }} {{ $issue['message'] ?? '' }} @if(isset($issue['reasons'])){{ implode(', ', $issue['reasons']) }}@endif</li>@endforeach</ul>
@if(isset($run->result['spans']))<p>Confirmed {{ number_format($run->result['confirmed_km'], 3) }} km · Unverified horizontal {{ number_format($run->result['unverified_horizontal_km'], 3) }} km · {{ $run->result['unresolved_spans'] }} unresolved spans.</p>
<table class="table"><thead><tr><th>Submission / PDF page / row</th><th>Start GPS_No</th><th>End GPS_No</th><th>3D meters</th><th>Validation</th></tr></thead><tbody>@foreach($run->result['spans'] as $span)<tr><td>{{ $span['survey'] }} / {{ $span['source_page'] ?? '—' }} / {{ $span['source_row'] ?? '—' }}</td><td>{{ $span['start'] }}</td><td>{{ $span['end'] }}</td><td>{{ $span['distance_m'] === null ? 'Unavailable' : number_format($span['distance_m'], 2) }}</td><td>{{ $span['confirmed'] ? 'Confirmed' : implode(', ', $span['reasons']) }}</td></tr>@endforeach</tbody></table>@endif
</details>@endif</td></tr>@endforeach
</tbody></table></div></section>
@endsection
