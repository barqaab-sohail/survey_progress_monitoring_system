@extends('layouts.app')
@section('title', 'Feeder HT + approved LT export')
@section('content')
<div class="page-head"><div><h1>Feeder HT + approved LT export</h1><p>Every download retains the complete imported HT network and verified HT updates, with approved General Duty LT networks available now. Dedicated transformers stay in HT. Meter points and service cables are excluded.</p></div><a class="btn btn-light" href="{{ route('transformers.index') }}">Transformer GIS data</a></div>
<section class="card">
    <form method="GET" action="{{ route('feeder-kmz.index') }}"><div class="actions"><label style="max-width:100%;min-width:0">Feeder<select style="max-width:100%;min-width:0" name="feeder_id" required><option value="">Choose a feeder</option>@foreach($feeders as $item)<option value="{{ $item->id }}" @selected($feeder?->id === $item->id)>{{ $item->feeder_code }} - {{ $item->feeder_name }}</option>@endforeach</select></label><button class="btn btn-primary">Refresh coverage</button></div></form>
</section>
@if($prepared)
@php($counts = $prepared['counts'])
<section class="card"><h2>{{ $feeder->feeder_code }} - {{ $feeder->feeder_name }}</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        @foreach(['total_transformers'=>'HT transformer records','general_duty'=>'General Duty','dedicated'=>'Dedicated (HT only)','approved_lt'=>'Approved LT included','pending_surveys'=>'General Duty surveys in progress / pending','unsurveyed_general'=>'General Duty not surveyed','excluded_lt'=>'LT excluded - needs attention','unclassified'=>'Duty not classified'] as $key=>$label)
        <div><strong style="font-size:1.5rem">{{ $counts[$key] }}</strong><p>{{ $label }}</p></div>
        @endforeach
    </div>
    <p><strong>LT export coverage:</strong> {{ $counts['approved_lt'] }} of {{ $counts['general_duty'] }} classified General Duty transformers ({{ $counts['completion_percent'] === null ? 'not available' : $counts['completion_percent'].'%' }}).</p>
    @if($counts['unclassified'])<p>Unclassified duties are shown separately and are not counted as General Duty or Dedicated. Complete their duty classification to establish full survey coverage.</p>@endif
    <p><strong>{{ $counts['total_transformers'] }} HT transformer records retained; {{ $counts['approved_lt'] }} approved LT networks included.</strong> You can export HT only before the first LT approval. Refresh this page after further approvals.</p>
    @if($prepared['ready'])
        <a class="btn btn-primary" href="{{ route('feeder-kmz.export', $feeder) }}">Export current HT + approved LT</a>
    @else
        <p>The HT source needs attention before downloading:</p><ul>@foreach($prepared['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
    @endif
    @if($prepared['warnings'])
        <details><summary>Excluded updates and LT surveys ({{ count($prepared['warnings']) }})</summary><p>These records do not block the current export. They will be reconsidered when you download again.</p><ul>@foreach($prepared['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></details>
    @endif
    <details><summary>Transformer coverage details ({{ count($prepared['coverage']) }})</summary><div class="table-wrap"><table class="table"><thead><tr><th>Transformer</th><th>Duty</th><th>Current LT coverage</th><th>Batch / approved revision</th><th>Survey / approval date</th><th>Pole points</th><th>Distribution lines</th></tr></thead><tbody>@foreach($prepared['coverage'] as $row)<tr><td>{{ $row['code'] }}</td><td>{{ $row['duty'] }}</td><td>{{ $row['status'] }}@if($row['reason'])<small>{{ $row['reason'] }}</small>@endif</td><td>{{ $row['batch_id'] ? '#'.$row['batch_id'].' / '.$row['revision'] : '-' }}</td><td>{{ $row['survey_date'] ?? '-' }}<small>{{ $row['approval_date'] ?? '-' }}</small></td><td>{{ $row['poles'] }}</td><td>{{ $row['lines'] }}</td></tr>@endforeach</tbody></table></div></details>
</section>
@if($prepared['ready'])
@include('feeder-kmz.preview', ['previewUrl'=>route('feeder-kmz.preview', $feeder)])
@endif
<section class="card" id="export-history"><h2>Export history</h2><p>New exports are retained with their exact coverage and approved revisions. Refresh this page after downloading to see the latest entry.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Export</th><th>Created (PKT)</th><th>Created by</th><th>HT / approved LT</th><th>LT changes</th><th></th></tr></thead><tbody>
@forelse($history as $record)
<tr><td>#{{ $record->id }}<small>{{ number_format($record->bytes / 1024, 1) }} KB</small></td><td>{{ $record->created_at->timezone('Asia/Karachi')->format('d M Y H:i:s') }}</td><td>{{ $record->creator?->name ?? 'Unknown user' }}</td><td>{{ $record->counts['total_transformers'] }} / {{ $record->counts['approved_lt'] }}</td><td>{{ count($record->changes['added']) }} added; {{ count($record->changes['removed']) }} removed; {{ count($record->changes['updated']) }} updated</td><td><a class="btn btn-sm btn-light" href="{{ route('feeder-kmz.history', $record) }}">View details / map</a> <a class="btn btn-sm btn-light" href="{{ route('feeder-kmz.history.download', $record) }}">Download saved KMZ</a></td></tr>
@empty<tr><td colspan="6">No saved exports yet. Use Export current HT + approved LT to create the first history entry.</td></tr>@endforelse
</tbody></table></div>{{ $history->fragment('export-history')->links() }}
</section>
@endif
@endsection
