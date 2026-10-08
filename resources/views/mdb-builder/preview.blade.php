@extends('layouts.app')
@section('title', 'Review transformer MDB network')
@section('content')
<div class="page-head"><div><h1>{{ $project->transformer_code }}: network review</h1><p>{{ $project->feeder->feeder_name }} &middot; Saved revision {{ $project->revision }}</p></div><div class="actions">@if(auth()->user()->hasRole('super_admin'))<a class="btn btn-light" href="{{ route('mdb-builder.edit', $project) }}">Edit survey / fix links</a>@endif<a class="btn btn-light" href="{{ route('mdb-builder.index') }}">All workspaces</a></div></div>
@if($problems)
<section class="card"><h2>Complete the network before creating MDB</h2><ul>@foreach($problems as $messages)@foreach($messages as $message)<li>{{ $message }}</li>@endforeach @endforeach</ul><p>Your survey remains saved. Correct the fields, save, and review again.</p></section>
@else
<section class="card"><h2>Network ready to export</h2><p>{{ $network['node_count'] }} nodes &middot; {{ $network['section_count'] }} sections &middot; {{ count($project->rows) }} paper rows &middot; {{ count($project->solar) }} solar entries</p>
<p>Coordinates: WGS84 / UTM {{ $project->export_settings['utm_zone'] }}N. Section lengths are calculated in metres from the linked positions.</p>
<ul>@foreach($network['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
@if(auth()->user()->hasRole('super_admin'))<form method="POST" action="{{ route('mdb-builder.export', $project) }}">@csrf<input type="hidden" name="revision" value="{{ $project->revision }}"><button class="btn btn-primary">Create and download MDB</button></form>@endif
</section>
@php
    $nodes = collect($network['tables']['Node'])->keyBy('NodeId');
    $minX = $nodes->min('X'); $maxX = $nodes->max('X'); $minY = $nodes->min('Y'); $maxY = $nodes->max('Y');
    $span = max($maxX - $minX, $maxY - $minY, 1);
    $xy = fn ($node) => [40 + ($node['X']-$minX)/$span*700, 740 - ($node['Y']-$minY)/$span*700];
@endphp
<section class="card"><h2>Linked network</h2><svg viewBox="0 0 800 800" role="img" aria-label="Transformer and linked survey sections" style="width:100%;max-height:560px;background:#f5f8fc;border:1px solid var(--line)">
@foreach($network['tables']['InstSection'] as $section)@php($from=$xy($nodes[$section['FromNodeId']]))@php($to=$xy($nodes[$section['ToNodeId']]))<line x1="{{ $from[0] }}" y1="{{ $from[1] }}" x2="{{ $to[0] }}" y2="{{ $to[1] }}" stroke="#3484ad" stroke-width="2"><title>{{ $section['SectionId'] }}: {{ round($section['SectionLength_MUL'],2) }} m</title></line>@endforeach
@foreach($nodes as $node)@php($position=$xy($node))<circle cx="{{ $position[0] }}" cy="{{ $position[1] }}" r="{{ $node['NodeId']==='TX-LV' ? 7 : 3 }}" fill="{{ $node['NodeId']==='TX-LV' ? '#e29025' : '#183b56' }}"><title>{{ $node['Description'] ?: $node['NodeId'] }}</title></circle>@if(!in_array($node['NodeId'],[$network['tables']['InstFeeders'][0]['FeederId'],'TX-HV']))<text x="{{ $position[0]+8 }}" y="{{ $position[1]-5 }}" font-size="12" fill="#183b56">{{ $node['NodeId']==='TX-LV' ? 'Transformer' : $node['Description'] }}</text>@endif @endforeach
</svg></section>
<section class="card table-wrap"><h2>S/E links</h2><table class="table"><thead><tr><th>Pair</th><th>S waypoint</th><th>E waypoint</th><th>Length (m)</th><th>Connection</th></tr></thead><tbody>@foreach($network['pairs'] as $pair)<tr><td>{{ $pair['pair'] }}</td><td>{{ $pair['from'] }}</td><td>{{ $pair['to'] }}</td><td>{{ $pair['length'] }}</td><td>{{ $pair['note'] }}</td></tr>@endforeach</tbody></table></section>
@endif
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-builder.css') }}">@endpush
