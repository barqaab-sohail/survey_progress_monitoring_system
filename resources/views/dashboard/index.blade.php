@extends('layouts.app')
@section('title','Management Dashboard')
@section('content')
<div class="management-dashboard">
<div class="page-head management-page-head">
    <div>
        <span class="eyebrow">Management command view</span>
        <h1>HAZECO delivery position</h1>
        <p>Where work is complete, where it is waiting, and where additional resources will have the greatest impact.</p>
        <span class="data-time">Updated {{ now()->format('d M Y, h:i A') }}</span>
    </div>
    <div class="actions"><button onclick="window.print()" class="btn btn-light">Print / PDF</button></div>
</div>

@if($summary['demo_feeders'] > 0)
<div class="dashboard-notice demo-notice">
    <strong>Sample progress data is active</strong>
    <span>{{ $summary['demo_feeders'] }} imported HAZECO feeders have clearly flagged demonstration baselines and transactions so management can evaluate this dashboard. Remove them before entering live progress.</span>
</div>
@endif

@if($summary['baseline_pending_feeders'] > 0)
<div class="dashboard-notice baseline-notice">
    <strong>Baseline coverage: {{ $summary['baselined_feeders'] }} of {{ $summary['total_feeders'] }} feeders</strong>
    <span>{{ $summary['baseline_pending_feeders'] }} feeders are excluded from percentage calculations until their transformer baselines are verified.</span>
</div>
@endif

<section class="executive-graph-grid">
    <article class="card graph-card overall-graph-card">
        <div class="graph-card-head">
            <div><span class="eyebrow">Whole project</span><h2>Overall delivery progress</h2></div>
            <div class="scope-total"><strong>{{ number_format($summary['total_transformers']) }}</strong><span>transformers in measured scope</span></div>
        </div>
        <canvas id="overallProgressChart" class="management-chart overall-chart" aria-label="Overall survey and MDB progress"></canvas>
    </article>

    <article class="card visual-action visual-action-{{ $decision['tone'] }}">
        <span class="visual-action-label">Management action</span>
        <div class="action-icon">!</div>
        <h2>{{ $decision['focus'] }}</h2>
        <div class="action-backlog"><span>Largest active constraint</span><strong>{{ number_format(max($summary['survey_pending'], $summary['mdb_creation_backlog'])) }}</strong></div>
        <div class="action-days">
            <div><strong>{{ $decision['survey_clear_days'] ?? '—' }}</strong><span>days<br>survey</span></div>
            <div class="versus">VS</div>
            <div><strong>{{ $decision['mdb_clear_days'] ?? '—' }}</strong><span>days<br>MDB</span></div>
        </div>
    </article>
</section>

<section class="graph-summary-grid">
    <article class="card graph-card donut-graph-card">
        <div class="graph-card-head"><div><span class="graph-step blue">01</span><h2>Survey status</h2></div><strong class="headline-percent blue-text">{{ number_format($summary['percentages']['survey_reported'], 1) }}%</strong></div>
        <canvas id="surveyStatusChart" class="management-chart donut-chart" aria-label="Survey verified, awaiting verification and not surveyed"></canvas>
        <div class="chart-legend" id="surveyLegend"></div>
    </article>

    <article class="card graph-card donut-graph-card">
        <div class="graph-card-head"><div><span class="graph-step green">02</span><h2>MDB creation status</h2></div><strong class="headline-percent green-text">{{ number_format($summary['percentages']['mdb_eligible'], 1) }}%</strong></div>
        <canvas id="mdbStatusChart" class="management-chart donut-chart" aria-label="MDB created and verified survey pending MDB creation"></canvas>
        <div class="chart-legend" id="mdbLegend"></div>
    </article>

    <article class="card graph-card clearance-graph-card">
        <div class="graph-card-head"><div><span class="eyebrow">Resource pressure</span><h2>Days to clear backlog</h2></div></div>
        <canvas id="clearanceChart" class="management-chart clearance-chart" aria-label="Estimated survey and MDB backlog clearance days"></canvas>
        <div class="rate-strip"><div><span>Survey/day</span><strong>{{ number_format($decision['survey_daily_rate'], 1) }}</strong></div><div><span>MDB/day</span><strong>{{ number_format($decision['mdb_daily_rate'], 1) }}</strong></div></div>
    </article>
</section>

<div class="section-title"><div><span class="eyebrow">Geographic allocation</span><h2>Circle-level resource priorities</h2></div></div>
<div class="card graph-card wide-graph-card">
    <div class="graph-key"><span><i class="survey-key"></i>Survey pending</span><span><i class="mdb-key"></i>MDB pending</span><small>Longer bar = greater need for resources</small></div>
    <canvas id="circleBacklogChart" class="management-chart comparison-chart" aria-label="Survey and MDB backlog by circle"></canvas>
</div>
<details class="detail-panel compact-detail">
    <summary><span><strong>View exact circle values</strong><small>Baseline coverage and completion percentages</small></span><b>Expand</b></summary>
    <div class="card table-wrap priority-table-wrap"><table class="table management-table"><thead><tr><th>Circle</th><th>Baseline coverage</th><th>Survey completed</th><th>Survey pending</th><th>MDB created / eligible</th><th>MDB pending</th><th>Suggested focus</th></tr></thead><tbody>@foreach($circleProgress as $row)<tr><td><strong>{{ $row->name }}</strong><small>{{ $row->feeders }} feeders total</small></td><td>{{ $row->baselined_feeders }} / {{ $row->feeders }}</td><td>{{ number_format($row->survey_reported) }} ({{ $row->survey_percentage }}%)</td><td>{{ number_format($row->survey_pending) }}</td><td>{{ number_format($row->mdb_created) }} / {{ number_format($row->survey_verified) }}</td><td>{{ number_format($row->mdb_backlog) }}</td><td>{{ $row->focus }}</td></tr>@endforeach</tbody></table></div>
</details>

<div class="section-title"><div><span class="eyebrow">Immediate attention</span><h2>Highest-backlog feeders</h2></div><span class="muted">Ranked by survey + MDB backlog</span></div>
<div class="card graph-card wide-graph-card">
    <div class="graph-key"><span><i class="survey-key"></i>Survey pending</span><span><i class="mdb-key"></i>MDB pending</span><small>Top eight feeders requiring attention</small></div>
    <canvas id="feederBacklogChart" class="management-chart feeder-chart" aria-label="Highest survey and MDB feeder backlogs"></canvas>
</div>

<div class="section-title"><div><span class="eyebrow">Recent production</span><h2>Daily output trend</h2></div><div class="actions no-print"><a class="btn btn-sm btn-light" href="?days=7">7 days</a><a class="btn btn-sm btn-light" href="?days=14">14 days</a><a class="btn btn-sm btn-light" href="?days=30">30 days</a></div></div>
<div class="card trend-card"><canvas id="trendChart" class="chart" aria-label="Daily survey, MDB creation and processing trend"></canvas><div class="legend"><span><i style="background:#1358a2"></i>Survey</span><span><i style="background:#16835b"></i>MDB created</span><span><i style="background:#c77800"></i>Processed</span></div></div>

<section class="grid period-grid">
@foreach(['Today'=>$today,'This week'=>$week,'This month'=>$month] as $period=>$totals)
    <div class="card period-card"><h3>{{ $period }}</h3><div class="mini-stats">@foreach(['survey_reported'=>'Survey','survey_verified'=>'Verified','mdb_created'=>'MDB created','mdb_processed'=>'Processed'] as $key=>$label)<div class="mini-stat"><span>{{ $label }}</span><strong>{{ number_format($totals[$key]) }}</strong></div>@endforeach</div></div>
@endforeach
</section>

<details class="detail-panel" id="feeder-progress">
    <summary><span><strong>Feeder-wise detailed progress</strong><small>{{ $feeders->count() }} feeders · open for operational detail</small></span><b>Expand</b></summary>
    <div class="card desktop-table table-wrap"><table class="table"><thead><tr><th>Feeder</th><th>Grid station</th><th>Total</th><th>Survey</th><th>Verified</th><th>Survey pending</th><th>Verification</th><th>MDB</th><th>MDB backlog</th><th>Status</th></tr></thead><tbody>@forelse($feeders as $feeder)<tr><td><strong>{{ $feeder->feeder_code }}</strong><br><small>{{ $feeder->feeder_name }}</small></td><td>{{ $feeder->gridStation?->name }}</td><td>{{ $feeder->total_transformers }}</td><td>{{ $feeder->survey_reported }}</td><td>{{ $feeder->survey_verified }}</td><td>{{ $feeder->survey_pending }}</td><td>{{ $feeder->verification_pending }}</td><td>{{ $feeder->mdb_created }}</td><td>{{ $feeder->mdb_creation_backlog }}</td><td><span class="status {{ $feeder->progress_status==='COMPLETED'?'status-completed':'' }}">{{ $feeder->progress_status }}</span></td></tr>@empty<tr><td colspan="10" class="empty">No feeder master data has been loaded.</td></tr>@endforelse</tbody></table></div>
    <div class="mobile-cards">@foreach($feeders as $feeder)<article class="mobile-record"><h3>{{ $feeder->feeder_code }}</h3><span class="muted">{{ $feeder->feeder_name }} · {{ $feeder->gridStation?->name }}</span><dl><div><dt>Total</dt><dd>{{ $feeder->total_transformers }}</dd></div><div><dt>Surveyed</dt><dd>{{ $feeder->survey_reported }}</dd></div><div><dt>Verified</dt><dd>{{ $feeder->survey_verified }}</dd></div><div><dt>Survey pending</dt><dd>{{ $feeder->survey_pending }}</dd></div><div><dt>MDB created</dt><dd>{{ $feeder->mdb_created }}</dd></div><div><dt>MDB pending</dt><dd>{{ $feeder->mdb_creation_backlog }}</dd></div></dl><span class="status">{{ $feeder->progress_status }}</span></article>@endforeach</div>
</details>

@if($surveyPerformance->isNotEmpty() || $mdbPerformance->isNotEmpty() || $processingPerformance->isNotEmpty())
<details class="detail-panel performance-panel">
    <summary><span><strong>Team and organization performance</strong><small>Daily, weekly, monthly and overall output</small></span><b>Expand</b></summary>
    <div class="section-title"><h2>Survey team performance</h2></div>
    <div class="card table-wrap"><table class="table"><thead><tr><th>Survey team</th><th>Reported today</th><th>Verified today</th><th>Returned rows</th><th>This week</th><th>This month</th><th>Overall</th></tr></thead><tbody>@foreach($surveyPerformance as $row)<tr><td><strong>{{ $row->name }}</strong></td><td>{{ $row->reported_today }}</td><td>{{ $row->verified_today }}</td><td>{{ $row->returned }}</td><td>{{ $row->this_week }}</td><td>{{ $row->this_month }}</td><td>{{ $row->overall }}</td></tr>@endforeach</tbody></table></div>
    <div class="section-title"><h2>MDB team production</h2></div>
    <div class="card table-wrap"><table class="table"><thead><tr><th>MDB user</th><th>Today</th><th>This week</th><th>This month</th><th>Overall</th></tr></thead><tbody>@foreach($mdbPerformance as $row)<tr><td><strong>{{ $row->name }}</strong></td><td>{{ $row->today }}</td><td>{{ $row->this_week }}</td><td>{{ $row->this_month }}</td><td>{{ $row->overall }}</td></tr>@endforeach</tbody></table></div>
    <div class="section-title"><h2>Processing organization performance</h2></div>
    <div class="card table-wrap"><table class="table"><thead><tr><th>Organization</th><th>Type</th><th>Assigned</th><th>Processed</th><th>Remaining</th><th>Today</th><th>This week</th><th>This month</th><th>Completion</th></tr></thead><tbody>@foreach($processingPerformance as $row)<tr><td><strong>{{ $row->name }}</strong></td><td>{{ strtoupper(str_replace('_',' ',$row->type)) }}</td><td>{{ $row->assigned }}</td><td>{{ $row->processed }}</td><td>{{ $row->remaining }}</td><td>{{ $row->today }}</td><td>{{ $row->this_week }}</td><td>{{ $row->this_month }}</td><td>{{ $row->completion }}%</td></tr>@endforeach</tbody></table></div>
</details>
@endif
</div>
@endsection

@push('scripts')
<script>
window.managementDashboardData = @json($chartData);
window.managementDashboardData.trend = @json($trend);
</script>
<script src="{{ asset('js/management-dashboard.js') }}?v=20261003-3"></script>
@endpush
