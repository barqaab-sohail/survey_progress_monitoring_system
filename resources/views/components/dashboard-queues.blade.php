<section class="grid kpi-grid dashboard-queue-grid" aria-label="Action queues">
@php($queueStages = auth()->user()->hasRole('survey_team_leader') ? ['baseline' => ['Baseline pending', 'baseline_pending_feeders'], 'survey' => ['Survey pending', 'survey_pending'], 'verification' => ['Awaiting survey review', 'verification_pending']] : ['baseline' => ['Baseline pending', 'baseline_pending_feeders'], 'survey' => ['Survey pending', 'survey_pending'], 'verification' => ['Awaiting survey review', 'verification_pending'], 'mdb_creation' => ['Ready for MDB creation', 'mdb_creation_backlog'], 'mdb_verification' => ['Awaiting MDB review', 'mdb_verification_pending'], 'mdb_returned' => ['Returned MDBs', 'mdb_returned']])
@foreach($queueStages as $stage => [$label, $key])
<a class="card kpi queue-link" href="{{ route('progress.queue', ['stage' => $stage]) }}"><span class="kpi-label">{{ $label }}</span><strong class="kpi-value">{{ number_format($summary[$key]) }}</strong><span class="queue-action">View queue <span aria-hidden="true">&rarr;</span></span></a>
@endforeach
</section>
