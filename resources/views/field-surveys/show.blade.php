@extends('layouts.app')
@section('title', $survey->transformer_code ?: 'Field survey draft')
@section('content')
<div class="page-head"><div><h1>{{ $survey->transformer_code ?: 'Untitled draft' }}</h1><p>{{ $survey->reference_snapshot['feeder_code'] ?? $survey->feeder->feeder_code }} &middot; {{ $survey->reference_snapshot['feeder_name'] ?? $survey->feeder->feeder_name }}</p></div><a class="btn btn-light" href="{{ route('field-surveys.index') }}">Back to field surveys</a></div>
<section class="card"><div class="section-title"><h2>Survey details</h2><span class="status">{{ ucfirst($survey->status) }}</span></div><dl class="detail-list">
    <div><dt>Survey date</dt><dd>{{ $survey->survey_date->format('d M Y') }}</dd></div><div><dt>Collected by / team</dt><dd>{{ $survey->collector->name }} / {{ $survey->team->name }}</dd></div>
    @foreach(['substation'=>'Substation','division'=>'Division','sub_division'=>'Sub Division','sub_division_code'=>'Sub Division code','transformer_make'=>'Transformer make','capacity_kva'=>'Capacity (kVA)','mounting'=>'Mounting','duty'=>'Duty','inspectors'=>'Inspectors','location'=>'Location'] as $key=>$label)
    <div><dt>{{ $label }}</dt><dd>{{ $survey->header[$key] ?? '-' }}</dd></div>
    @endforeach
    <div><dt>Last synced</dt><dd>{{ $survey->updated_at->format('d M Y, h:i A') }} &middot; Revision {{ $survey->revision }}</dd></div><div><dt>Remarks</dt><dd style="white-space:pre-wrap">{{ $survey->remarks ?: '-' }}</dd></div>
</dl></section>
<section class="card"><h2>Survey rows ({{ count($survey->rows) }})</h2>
@forelse($survey->rows as $row)
<article style="border-top:1px solid var(--line);padding:16px 0"><h3 style="margin-top:0">Row {{ $loop->iteration }} &middot; {{ $row['se'] ?? '-' }} @if(!empty($row['group'])) &middot; Group {{ $row['group'] }} @endif</h3><dl class="detail-list">
    @foreach(['date'=>'Date','gps_waypoint'=>'GPS waypoint','phase'=>'Phase','conductor_r'=>'Conductor R','conductor_y'=>'Conductor Y','conductor_b'=>'Conductor B','conductor_neutral'=>'Conductor neutral','equipment_type'=>'Equipment type','pole_class'=>'Pole class','pole_height_ft'=>'Pole height (ft)','intersection'=>'Int (paper field)','remarks'=>'Remarks'] as $key=>$label)
    <div><dt>{{ $label }}</dt><dd>{{ $row[$key] ?? '-' }}</dd></div>
    @endforeach
    <div><dt>GPS coordinates</dt><dd>@if(isset($row['latitude'],$row['longitude'])){{ $row['latitude'] }}, {{ $row['longitude'] }}@else Not captured @endif</dd></div><div><dt>GPS accuracy (m)</dt><dd>{{ $row['gps_accuracy_m'] ?? 'Not captured' }}</dd></div>
    <div style="grid-column:1/-1"><dt>Consumers (paper categories)</dt><dd>@foreach(['rs','rl','sc','lc','si','li','pb','ag','st'] as $category)<span style="display:inline-block;margin:4px 18px 4px 0">{{ strtoupper($category) }}: {{ $row['consumers'][$category] ?? '-' }}</span>@endforeach</dd></div>
</dl></article>
@empty<p>No rows recorded in this draft.</p>@endforelse
</section>
<section class="card"><h2>Solar / net metering ({{ count($survey->solar) }})</h2>
@forelse($survey->solar as $solar)
<dl class="detail-list" style="margin-bottom:16px"><div><dt>Consumer reference</dt><dd>{{ $solar['consumer_reference'] ?? '-' }}</dd></div><div><dt>Installed PV (kW)</dt><dd>{{ $solar['installed_pv_kw'] ?? '-' }}</dd></div><div><dt>Remarks</dt><dd>{{ $solar['remarks'] ?? '-' }}</dd></div></dl>
@empty<p>No solar entries recorded.</p>@endforelse
</section>
<section class="card"><h2>Photos and sketches ({{ $survey->attachments->count() }})</h2><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
@forelse($survey->attachments as $attachment)
<div><a href="{{ route('field-surveys.attachment', $attachment->client_uuid) }}" target="_blank" rel="noopener">@if(str_starts_with($attachment->mime_type, 'image/'))<img loading="lazy" src="{{ route('field-surveys.attachment', $attachment->client_uuid) }}" alt="{{ ucfirst($attachment->kind) }} for {{ $survey->transformer_code }}" style="display:block;max-width:100%;max-height:250px;object-fit:contain;margin-bottom:8px">@endif Open {{ $attachment->kind }} {{ $loop->iteration }}</a><p>{{ number_format($attachment->byte_length / 1024) }} KB</p></div>
@empty<p>No attachments uploaded.</p>@endforelse
</div></section>
@endsection
