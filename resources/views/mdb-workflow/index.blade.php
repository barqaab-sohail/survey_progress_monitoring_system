@extends('layouts.app')
@section('title', 'MDB creation and verification')
@section('content')
<div class="mdb-workflow">
    <nav class="mdb-tabs">
        @foreach(['entry'=>['edit','Data entry'], 'survey_verification'=>['surveyVerify','Survey verification'], 'processing'=>['process','Network processing'], 'mdb_verification'=>['analyze','Final MDB verification']] as $queue=>$details)
        @can('mdb-workflow.'.$details[0])<a href="{{ route('mdb-workflow.index', ['queue'=>$queue]) }}">{{ $details[1] }}</a>@endcan
        @endforeach
    </nav>
    <div class="page-head"><div><span class="eyebrow">Survey to engineering model</span><h1>MDB creation and verification</h1><p>Track source surveys, verified transformer networks, MDB outputs and SynerGEE acceptance.</p></div>
        @can('mdb-workflow.upload')<a class="btn btn-primary" href="{{ route('mdb-workflow.create') }}">Create survey batch</a>@endcan
    </div>
    <div class="mdb-metrics">
        @foreach(['surveyed_transformers'=>['Surveyed transformers','Transformer records'],'sections_entered'=>['Sections entered','Survey sections'],'validation_errors'=>['Validation errors','Current data revisions'],'awaiting_verification'=>['Awaiting verification','Survey batches'],'approved_transformers'=>['Approved transformers','Transformer records'],'export_failures'=>['Export failures','Export jobs'],'mdb_generated'=>['MDB generated','Current readable MDB outputs'],'analysis_accepted'=>['Analysis accepted','Currently accepted MDB outputs']] as $key=>$metric)
        <article class="card mdb-metric"><span class="kpi-label">{{ $metric[0] }}</span><strong class="kpi-value">{{ number_format($metrics[$key] ?? 0) }}</strong><small>{{ $metric[1] }}</small></article>
        @endforeach
    </div>
    <p class="mdb-note">Transformer record counts and export output counts are separate. Open a batch to see validation errors linked to the source PDF page and row.</p>
    <section class="card"><h2>Find survey batches</h2><form method="GET" action="{{ route('mdb-workflow.index') }}" class="mdb-fields mdb-filter-grid">
        <label>Project<select name="project_id"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->code }} | {{ $project->name }}</option>@endforeach</select></label>
        <label>Feeder<select name="feeder_id"><option value="">All feeders</option>@foreach($feeders as $feeder)<option value="{{ $feeder->id }}" @selected((string) request('feeder_id') === (string) $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label>
        <label>Transformer reference<input name="transformer" value="{{ request('transformer') }}" maxlength="100" placeholder="Code or name"></label>
        <label>Survey team<select name="survey_team_id"><option value="">All teams</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((string) request('survey_team_id') === (string) $team->id)>{{ $team->code }} | {{ $team->name }}</option>@endforeach</select></label>
        <label>Survey date from<input name="date_from" type="date" value="{{ request('date_from') }}"></label>
        <label>Survey date to<input name="date_to" type="date" value="{{ request('date_to') }}"></label>
        <label>Workflow status<select name="status"><option value="">All statuses</option>@foreach(['draft'=>'Source collection / data entry','submitted'=>'Submitted survey','awaiting_verification'=>'Awaiting verification','returned'=>'Returned for correction','approved'=>'Approved'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="actions mdb-filter-actions"><button class="btn btn-primary">Apply filters</button><a class="btn btn-light" href="{{ route('mdb-workflow.index') }}">Reset</a></div>
    </form></section>
    <section class="card table-wrap"><table class="table mdb-batch-table"><thead><tr><th>Survey batch</th><th>Project / feeder</th><th>Team / date</th><th>Transformer records</th><th>Source files</th><th>Workflow</th><th></th></tr></thead><tbody>
        @forelse($batches as $batch)
        <tr><td><strong>Batch #{{ $batch->id }}</strong><small>Revision {{ $batch->revision }}</small></td><td>{{ $batch->project?->name }}<small>{{ $batch->feeder?->feeder_code }} | {{ $batch->feeder?->feeder_name }}</small></td><td>{{ $batch->surveyTeam?->name ?? 'Unassigned' }}<small>{{ $batch->survey_date?->format('d M Y') }}</small></td><td>{{ $batch->transformers_count ?? $batch->transformers->count() }}</td><td>{{ $batch->sources_count ?? $batch->sources->count() }}</td><td><span class="status mdb-status-{{ $batch->status }}">{{ str($batch->status)->replace('_', ' ')->title() }}</span></td><td><a class="btn btn-sm btn-light" href="{{ route('mdb-workflow.show', $batch) }}">Open batch</a></td></tr>
        @empty<tr><td colspan="7" class="empty">No survey batches match these filters.</td></tr>@endforelse
    </tbody></table></section>
    {{ $batches->withQueryString()->links() }}
    @can('mdb-workflow.configure')
    <section class="card"><h2>Project engineering configuration</h2><div class="actions">@foreach($projects as $project)<a class="btn btn-light" href="{{ route('mdb-workflow.config', $project) }}">{{ $project->code }} settings and templates</a>@endforeach</div></section>
    @endcan
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
