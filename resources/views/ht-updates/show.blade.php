@extends('layouts.app')
@section('title', 'HT Network Update — '.$update->transformer_code)
@section('content')
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<p><a class="btn btn-primary" href="{{ route('feeder-kmz.index', ['feeder_id' => $update->feeder_id]) }}">Feeder HT + approved LT</a></p>@endif
@php($p = $update->payload)
<div class="page-head"><div><h1>HT Network Update — {{ $update->transformer_code }}</h1><p>{{ $update->feeder->feeder_code }} · {{ $update->feeder->feeder_name }} · {{ ucfirst($update->status) }} · Revision {{ $update->revision }}</p></div><a class="btn btn-light" href="{{ route('ht-updates.index') }}">HT updates</a></div>
@if($update->collected_by === auth()->id() && $update->status !== 'verified' && app(\App\Services\HtNetworkWorkflow::class)->canCreateWeb(auth()->user()))<p><a class="btn btn-primary" href="{{ route('ht-updates.edit', $update) }}">Edit HT Network Update</a></p>@endif
<section class="card"><h2>HT connection and transformer</h2><p>HT feeder data update. Verify the HT connection before starting LT survey or PDF data entry.</p><dl class="detail-list">
@foreach(['kind'=>'Update type','capacity_kva'=>'Capacity (kVA)','make'=>'Make','location'=>'Location','mounting'=>'Mounting','observed_on'=>'Observed on','upstream_feature_id'=>'Selected HT feature','upstream_reference'=>'Upstream connection reference','remarks'=>'Field evidence / remarks'] as $key=>$label)
<div><dt>{{ $label }}</dt><dd>{{ $p[$key] ?? 'Not selected' }}</dd></div>
@endforeach
<div><dt>Review note</dt><dd>{{ $update->review_note ?: 'Pending independent verification' }}</dd></div></dl>
<h3>Field photographs</h3><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">@foreach($p['photos'] as $photo)<a href="{{ route('ht-updates.photo', [$update, $loop->index]) }}" target="_blank" rel="noopener"><img style="max-width:100%;max-height:260px" src="{{ route('ht-updates.photo', [$update, $loop->index]) }}" alt="HT connection evidence {{ $loop->iteration }}"></a>@endforeach</div></section>
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']) && $update->status === 'pending' && $update->collected_by !== auth()->id())
<section class="card"><h2>Resolve waypoint coordinates</h2><p>Upload a GPX file to fill exact waypoint-name matches. Missing or repeated names need manual review. Coordinates are committed only when verified.</p>
<form method="POST" enctype="multipart/form-data" action="{{ route('ht-updates.gpx', $update) }}">@csrf<input type="hidden" name="revision" value="{{ $update->revision }}"><input type="file" name="gpx" accept=".gpx" required><button class="btn btn-light">Preview GPX matches</button></form></section>
<section class="card"><h2>Independent HT verification</h2><p>Check the route, conductor details, photographs and actual upstream connection. The first coordinate must meet the selected client HT feature.</p>
<form method="POST" action="{{ route('ht-updates.review', $update) }}">@csrf<input type="hidden" name="revision" value="{{ $update->revision }}">
<div style="overflow:auto"><table class="table"><thead><tr><th>Route order</th><th>Waypoint</th><th>Pole / height (ft)</th><th>Conductors R / Y / B</th><th>Latitude</th><th>Longitude</th></tr></thead><tbody>
@foreach($p['nodes'] as $i=>$node)<tr><td>{{ $loop->first ? 'Existing HT connection' : ($loop->last ? 'Transformer' : 'New HT pole') }}</td><td>{{ $node['waypoint'] }}</td><td>{{ $node['pole_type'] ?? '-' }} / {{ $node['pole_height'] ?? '-' }}</td><td>{{ $node['conductor_r'] ?? '-' }} / {{ $node['conductor_y'] ?? '-' }} / {{ $node['conductor_b'] ?? '-' }}</td><td><input aria-label="Latitude {{ $i+1 }}" name="nodes[{{ $i }}][latitude]" value="{{ old('nodes.'.$i.'.latitude', $node['latitude'] ?? '') }}" type="number" step="any" min="-90" max="90"></td><td><input aria-label="Longitude {{ $i+1 }}" name="nodes[{{ $i }}][longitude]" value="{{ old('nodes.'.$i.'.longitude', $node['longitude'] ?? '') }}" type="number" step="any" min="-180" max="180"></td></tr>@endforeach
</tbody></table></div>
<label>Existing transformer ID (only for explicitly reconciling a matching new client record)<input name="existing_transformer_id" type="number" min="1" value="{{ old('existing_transformer_id') }}"></label>
<label>Review note / reason<textarea name="review_note" required maxlength="4000">{{ old('review_note') }}</textarea></label>
<p><label><input type="checkbox" name="connection_confirmed" value="1"> I checked the actual upstream connection, complete route and transformer identity.</label></p>
<button class="btn btn-primary" name="decision" value="verified">Verify HT Network Update</button> <button class="btn btn-light" name="decision" value="returned">Return for correction — retain LT work</button>
</form></section>
@else
<section class="card"><h2>HT route</h2>@foreach($p['nodes'] as $node)<p>{{ $loop->iteration }}. {{ $node['waypoint'] }} · {{ $node['latitude'] ?? 'Latitude pending' }}, {{ $node['longitude'] ?? 'Longitude pending' }}</p>@endforeach</section>
@endif
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<a class="btn btn-primary" href="{{ route('ht-updates.export', $update->feeder_id) }}">Download feeder KMZ — verified HT updates only</a>@endif
@endsection
