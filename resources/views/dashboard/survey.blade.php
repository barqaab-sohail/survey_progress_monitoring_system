@extends('layouts.app')
@section('title', 'Survey Dashboard')
@section('content')
@include('components.dashboard-queues')
<div class="page-head survey-page-head">
    <div>
        <span class="eyebrow">Field survey operations</span>
        <h1>Survey Team Dashboard</h1>
        <p>Progress for feeders assigned to your survey team.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="{{ route('survey.create') }}">Add daily survey</a>
    </div>
</div>

@if($summary['baseline_pending_feeders'] > 0)
    <div class="dashboard-notice baseline-notice">
        <strong>Baseline pending:</strong>
        {{ number_format($summary['baseline_pending_feeders']) }} assigned {{ Str::plural('feeder', $summary['baseline_pending_feeders']) }} will be measured after transformer totals are verified.
    </div>
@endif

<section class="grid kpi-grid survey-kpi-grid" aria-label="Survey summary">
    <article class="card kpi"><span class="kpi-accent"></span><span class="kpi-label">Assigned feeders</span><strong class="kpi-value">{{ number_format($summary['total_feeders']) }}</strong></article>
    <article class="card kpi"><span class="kpi-accent"></span><span class="kpi-label">Transformer baseline</span><strong class="kpi-value">{{ number_format($summary['total_transformers']) }}</strong></article>
    <article class="card kpi accent-green"><span class="kpi-accent"></span><span class="kpi-label">Survey reported</span><strong class="kpi-value">{{ number_format($summary['survey_reported']) }}</strong></article>
    <article class="card kpi accent-amber"><span class="kpi-accent"></span><span class="kpi-label">Awaiting verification</span><strong class="kpi-value">{{ number_format($summary['verification_pending']) }}</strong></article>
    <article class="card kpi accent-red"><span class="kpi-accent"></span><span class="kpi-label">Survey pending</span><strong class="kpi-value">{{ number_format($summary['survey_pending']) }}</strong></article>
</section>

<section class="survey-overview-grid">
    <article class="card survey-progress-card">
        <div class="card-heading"><div><span class="eyebrow">Assigned workload</span><h2>Survey completion</h2></div></div>
        <div class="capacity-row">
            <div class="capacity-title"><span>Field survey reported</span><strong>{{ number_format($summary['percentages']['survey_reported'], 1) }}%</strong></div>
            <div class="capacity-track"><span class="capacity-fill survey-fill" style="width:{{ min($summary['percentages']['survey_reported'], 100) }}%"></span></div>
            <p class="management-note">{{ number_format($summary['survey_reported']) }} of {{ number_format($summary['total_transformers']) }} transformers reported.</p>
        </div>
        <div class="capacity-row compact">
            <div class="capacity-title"><span>Survey verified</span><strong>{{ number_format($summary['percentages']['survey_verified'], 1) }}%</strong></div>
            <div class="capacity-track"><span class="capacity-fill verify-fill" style="width:{{ min($summary['percentages']['survey_verified'], 100) }}%"></span></div>
            <p class="management-note">{{ number_format($summary['survey_verified']) }} transformers verified.</p>
        </div>
    </article>

    <article class="card survey-status-card">
        <div class="survey-progress-ring" style="--value:{{ min($summary['percentages']['survey_reported'], 100) }}">
            <div><strong>{{ number_format($summary['percentages']['survey_reported'], 1) }}%</strong><span>REPORTED</span></div>
        </div>
        <div class="survey-status-counts">
            <div><span>Remaining in field</span><strong>{{ number_format($summary['survey_pending']) }}</strong></div>
            <div><span>With verification team</span><strong>{{ number_format($summary['verification_pending']) }}</strong></div>
        </div>
    </article>
</section>

<div class="section-title"><div><span class="eyebrow">Recent output</span><h2>Survey reporting periods</h2></div></div>
<section class="grid period-grid">
@foreach(['Today' => $today, 'This week' => $week, 'This month' => $month] as $period => $totals)
    <article class="card period-card">
        <h3>{{ $period }}</h3>
        <div class="mini-stats">
            <div class="mini-stat"><span>Survey reported</span><strong>{{ number_format($totals['survey_reported']) }}</strong></div>
            <div class="mini-stat"><span>Survey verified</span><strong>{{ number_format($totals['survey_verified']) }}</strong></div>
        </div>
    </article>
@endforeach
</section>

@if($circleProgress->isNotEmpty())
<div class="section-title"><div><span class="eyebrow">Area position</span><h2>Circle survey progress</h2></div></div>
<section class="grid survey-circle-grid">
@foreach($circleProgress as $circle)
    <article class="card survey-circle-card">
        <div class="capacity-title"><strong>{{ $circle->name }}</strong><strong>{{ number_format($circle->percentage, 1) }}%</strong></div>
        <div class="capacity-track"><span class="capacity-fill survey-fill" style="width:{{ min($circle->percentage, 100) }}%"></span></div>
        <div class="survey-circle-stats">
            <span>Reported <b>{{ number_format($circle->survey_reported) }}</b></span>
            <span>Pending <b>{{ number_format($circle->survey_pending) }}</b></span>
            <span>Verification <b>{{ number_format($circle->verification_pending) }}</b></span>
        </div>
    </article>
@endforeach
</section>
@endif

@if($priorityFeeders->isNotEmpty())
<div class="section-title"><div><span class="eyebrow">Immediate attention</span><h2>Highest survey backlog feeders</h2></div></div>
<section class="grid priority-feeder-grid">
@foreach($priorityFeeders as $feeder)
    <article class="card priority-feeder">
        <div class="priority-feeder-head"><div><strong>{{ $feeder->feeder_code }}</strong><span>{{ $feeder->feeder_name }}</span></div><span class="backlog-total">{{ number_format($feeder->survey_pending) }} pending</span></div>
        <div class="priority-metric"><span><span>Survey reported</span><strong>{{ number_format($feeder->survey_reported) }} / {{ number_format($feeder->total_transformers) }}</strong></span><div><i class="survey-fill" style="width:{{ $feeder->total_transformers ? min($feeder->survey_reported / $feeder->total_transformers * 100, 100) : 0 }}%"></i></div></div>
        <div class="priority-metric"><span><span>Awaiting verification</span><strong>{{ number_format($feeder->verification_pending) }}</strong></span></div>
        <small>{{ $feeder->gridStation?->name }}</small>
    </article>
@endforeach
</section>
@endif

<div class="section-title"><div><span class="eyebrow">Assigned work</span><h2>Feeder-wise survey position</h2></div></div>
<div class="card desktop-table table-wrap">
    <table class="table survey-table">
        <thead><tr><th>Feeder</th><th>Grid station</th><th>Baseline</th><th>Reported</th><th>Verified</th><th>Survey pending</th><th>Awaiting verification</th><th>Survey status</th></tr></thead>
        <tbody>
        @forelse($feeders as $feeder)
            <tr>
                <td><strong>{{ $feeder->feeder_code }}</strong><br><small>{{ $feeder->feeder_name }}</small></td>
                <td>{{ $feeder->gridStation?->name }}</td>
                <td>{{ number_format($feeder->total_transformers) }}</td>
                <td>{{ number_format($feeder->survey_reported) }}</td>
                <td>{{ number_format($feeder->survey_verified) }}</td>
                <td>{{ number_format($feeder->survey_pending) }}</td>
                <td>{{ number_format($feeder->verification_pending) }}</td>
                <td><span class="status {{ $feeder->survey_status === 'SURVEY COMPLETE' ? 'status-completed' : ($feeder->survey_status === 'VERIFICATION PENDING' ? 'status-pending' : '') }}">{{ $feeder->survey_status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">No feeders are assigned to your survey team.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mobile-cards">
@forelse($feeders as $feeder)
    <article class="mobile-record">
        <h3>{{ $feeder->feeder_code }}</h3>
        <span class="muted">{{ $feeder->feeder_name }} &middot; {{ $feeder->gridStation?->name }}</span>
        <dl>
            <div><dt>Baseline</dt><dd>{{ number_format($feeder->total_transformers) }}</dd></div>
            <div><dt>Reported</dt><dd>{{ number_format($feeder->survey_reported) }}</dd></div>
            <div><dt>Verified</dt><dd>{{ number_format($feeder->survey_verified) }}</dd></div>
            <div><dt>Pending</dt><dd>{{ number_format($feeder->survey_pending) }}</dd></div>
        </dl>
        <span class="status">{{ $feeder->survey_status }}</span>
    </article>
@empty
    <div class="card empty">No feeders are assigned to your survey team.</div>
@endforelse
</div>
@endsection
