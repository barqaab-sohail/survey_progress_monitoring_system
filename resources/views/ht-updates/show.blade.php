@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/ht-updates.css').'?v='.filemtime(public_path('css/ht-updates.css')) }}">
@endpush
@section('title', 'HT Network Update — '.$update->transformer_code)
@section('content')
<div class="ht-update ht-update-review">
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<p><a class="btn btn-primary" href="{{ route('feeder-kmz.index', ['feeder_id' => $update->feeder_id]) }}">Feeder HT + approved LT</a></p>@endif
@php($p = $update->payload)
<section class="card"><h2>Updated HT feeder KMZ</h2>
@if($update->status === 'verified')
<p>This addition is approved. The download contains the original feeder, all verified transformers, new poles and connecting HT spans. Keep it for future use in Google Earth.</p>
@else
<p>This addition is {{ $update->status === 'returned' ? 'returned for correction' : 'awaiting HT approval' }}. It will appear in the updated feeder KMZ after approval. The current download includes previously verified additions only.</p>
@endif
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<a class="btn btn-primary" href="{{ route('ht-updates.export', $update->feeder_id) }}">{{ $update->status === 'verified' ? 'Download updated HT feeder KMZ' : 'Download current approved HT feeder KMZ' }}</a>@endif
</section>
<div class="page-head"><div><h1>HT Network Update — {{ $update->transformer_code }}</h1><p>{{ $update->feeder->feeder_code }} · {{ $update->feeder->feeder_name }} · {{ ucfirst($update->status) }} · Revision {{ $update->revision }}</p></div><a class="btn btn-light" href="{{ route('ht-updates.index') }}">HT updates</a></div>
@if(($update->collected_by === auth()->id() || auth()->user()->hasRole('super_admin')) && $update->status !== 'verified' && app(\App\Services\HtNetworkWorkflow::class)->canCreateWeb(auth()->user()))<p><a class="btn btn-primary" href="{{ route('ht-updates.edit', $update) }}">Edit HT Network Update</a></p>@endif
<section class="card"><h2>HT connection and transformer</h2><p>HT feeder data update. Verify the HT connection before starting LT survey or PDF data entry.</p><dl class="detail-list">
@foreach(['kind'=>'Update type','capacity_kva'=>'Capacity (kVA)','make'=>'Make','location'=>'Location','mounting'=>'Mounting','observed_on'=>'Observed on','upstream_feature_id'=>'Selected HT feature','upstream_reference'=>'Upstream connection reference','remarks'=>'Field evidence / remarks'] as $key=>$label)
<div><dt>{{ $label }}</dt><dd>{{ $key === 'kind' ? (($p[$key] ?? '') === 'missing_transformer' ? 'Transformer only — no new poles' : 'New poles / HT extension, then transformer') : ($p[$key] ?? 'Not selected') }}</dd></div>
@endforeach
<div><dt>Review note</dt><dd>{{ $update->review_note ?: 'Pending HT verification' }}</dd></div></dl>
<h3>Field photographs</h3><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">@foreach($p['photos'] as $photo)<a href="{{ route('ht-updates.photo', [$update, $loop->index]) }}" target="_blank" rel="noopener"><img style="max-width:100%;max-height:260px" src="{{ route('ht-updates.photo', [$update, $loop->index]) }}" alt="HT connection evidence {{ $loop->iteration }}"></a>@endforeach</div></section>
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']) && $update->status === 'pending' && ($update->collected_by !== auth()->id() || auth()->user()->hasRole('super_admin')))
<section class="card"><h2>Resolve waypoint coordinates</h2><p>Upload a GPX file to fill exact waypoint-name matches. Missing or repeated names need manual review. Coordinates are committed only when verified.</p>
<form method="POST" enctype="multipart/form-data" action="{{ route('ht-updates.gpx', $update) }}">@csrf<input type="hidden" name="revision" value="{{ $update->revision }}"><input type="file" name="gpx" accept=".gpx" required><button class="btn btn-light">Preview GPX matches</button></form></section>
<section class="card ht-verification" aria-labelledby="ht-verification-title">
<div class="ht-review-heading"><div><span class="ht-eyebrow">Review and approve</span><h2 id="ht-verification-title">HT verification</h2></div><span class="ht-review-status">Pending verification</span></div>
<p>Check the route, conductor details and photographs before approving. The existing connection coordinates must meet the selected HT feature.</p>
<form method="POST" action="{{ route('ht-updates.review', $update) }}">
@csrf<input type="hidden" name="revision" value="{{ $update->revision }}">
<div class="ht-review-section"><h3>1. Check route coordinates</h3><p>Route rows run from the existing HT connection to the new transformer. Latitude and longitude use decimal degrees.</p>
<div class="ht-review-table-wrap"><table class="table ht-review-table"><caption class="ht-sr-only">HT route and coordinates for verification</caption><thead><tr><th scope="col">Route / waypoint</th><th scope="col">Pole details</th><th scope="col">Conductors R / Y / B</th><th scope="col">Latitude</th><th scope="col">Longitude</th></tr></thead><tbody>
@foreach($p['nodes'] as $i=>$node)
@php($routeLabel = $loop->first ? 'Existing HT connection' : ($loop->last ? 'New transformer' : 'New HT pole '.$i))
<tr><td data-label="Route / waypoint"><div class="ht-route-identity"><span class="ht-route-number">{{ $loop->iteration }}</span><div><strong>{{ $routeLabel }}</strong><small>Waypoint {{ $node['waypoint'] }}</small></div></div></td>
<td data-label="Pole details"><span>{{ ($node['pole_type'] ?? '') ?: 'Not recorded' }}</span><small>{{ isset($node['pole_height']) && $node['pole_height'] !== '' ? $node['pole_height'].' ft' : 'Height not recorded' }}</small></td>
<td data-label="Conductors R / Y / B"><span class="ht-conductor-values">{{ $node['conductor_r'] ?? '-' }} / {{ $node['conductor_y'] ?? '-' }} / {{ $node['conductor_b'] ?? '-' }}</span></td>
@foreach(['latitude'=>[-90,90], 'longitude'=>[-180,180]] as $axis=>$range)
<td data-label="{{ ucfirst($axis) }}"><label class="ht-sr-only" for="ht-review-{{ $axis }}-{{ $i }}">{{ ucfirst($axis) }} for {{ $routeLabel }}, waypoint {{ $node['waypoint'] }}</label><input id="ht-review-{{ $axis }}-{{ $i }}" name="nodes[{{ $i }}][{{ $axis }}]" value="{{ old('nodes.'.$i.'.'.$axis, $node[$axis] ?? '') }}" type="number" step="any" min="{{ $range[0] }}" max="{{ $range[1] }}" placeholder="{{ $axis === 'latitude' ? 'e.g. 34.123456' : 'e.g. 73.123456' }}" @error('nodes.'.$i.'.'.$axis) aria-invalid="true" aria-describedby="ht-error-{{ $axis }}-{{ $i }}" @enderror>
@error('nodes.'.$i.'.'.$axis)<small class="ht-field-error" id="ht-error-{{ $axis }}-{{ $i }}">{{ $message }}</small>@enderror</td>
@endforeach
</tr>
@endforeach
</tbody></table></div></div>
<div class="ht-review-section"><h3>2. Record your review</h3>
<label for="ht-review-note">Review note / reason <span class="ht-required">Required</span></label><p class="ht-field-help" id="ht-review-note-help">Summarize what you checked, or explain what needs correction.</p><textarea id="ht-review-note" name="review_note" required maxlength="4000" rows="4" aria-describedby="ht-review-note-help" placeholder="Record the connection, route and transformer checks...">{{ old('review_note') }}</textarea>
@error('review_note')<small class="ht-field-error">{{ $message }}</small>@enderror
<details class="ht-reconcile" @if(old('existing_transformer_id') || $errors->has('existing_transformer_id')) open @endif><summary>Reconcile an existing transformer (optional)</summary><p>Use only when a matching transformer was added to the client records after this update. Otherwise leave this blank.</p><label for="ht-existing-transformer">Existing transformer ID</label><input id="ht-existing-transformer" name="existing_transformer_id" type="number" min="1" value="{{ old('existing_transformer_id') }}" placeholder="Enter matching transformer ID">
@error('existing_transformer_id')<small class="ht-field-error">{{ $message }}</small>@enderror</details>
</div>
<div class="ht-review-section"><h3>3. Confirm and decide</h3><label class="ht-review-confirm" for="ht-connection-confirmed"><input id="ht-connection-confirmed" type="checkbox" name="connection_confirmed" value="1" @checked(old('connection_confirmed'))><span><strong>I confirm the HT connection and route</strong><small>I checked the actual upstream connection, complete route and transformer identity.</small></span></label>
@error('connection_confirmed')<small class="ht-field-error">{{ $message }}</small>@enderror
<div class="ht-review-actions"><button type="submit" class="btn btn-primary" name="decision" value="verified">Verify HT Network Update</button><button type="submit" class="btn btn-light" name="decision" value="returned">Return for correction</button></div><p class="ht-review-footer">Verified additions are included in the updated feeder KMZ. Returning for correction retains existing LT work.</p></div>
</form></section>
@else
<section class="card"><h2>HT route</h2>@foreach($p['nodes'] as $node)<p>{{ $loop->iteration }}. {{ $node['waypoint'] }} · {{ $node['latitude'] ?? 'Latitude pending' }}, {{ $node['longitude'] ?? 'Longitude pending' }}</p>@endforeach</section>
@endif
</div>
@endsection
