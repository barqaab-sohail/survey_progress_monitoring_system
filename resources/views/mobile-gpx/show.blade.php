@extends('layouts.app')
@section('title', 'Link GPX — '.$survey->survey_name)
@section('content')
<div class="page-head"><div><h1>{{ $survey->survey_name }}</h1><p>{{ $survey->feeder->feeder_code }} / {{ $survey->feeder->feeder_name }} · {{ $survey->team->name }} · {{ $survey->collector->name }} · Revision {{ $survey->revision }}</p></div><a class="btn" href="{{ route('mobile-gpx.index') }}">Back to GPX linking</a></div>
<div class="card"><p>Survey date: {{ $survey->survey_date->format('d M Y') }}. Match the current S/E references; the historical first S retains its original identity and the existing starting-coordinate policy.</p>
@if($previous && !$current)<p><strong>Previous confirmation is outdated. Preview and confirm the current revision before JSON export.</strong></p>@endif
@if($current)<p><strong>GPX confirmed</strong> on {{ $current->created_at->format('d M Y H:i') }} for revision {{ $current->survey_revision }}.</p>
<form method="POST" action="{{ route('mobile-gpx.export', $survey->client_uuid) }}">@csrf<input type="hidden" name="survey_revision" value="{{ $survey->revision }}"><input type="hidden" name="link_id" value="{{ $current->id }}"><button class="btn btn-primary">Download confirmed SynerGEE JSON</button></form>
<p>On the local Windows application, open <strong>JSON to SynerGEE</strong>, upload this JSON, review the model and generate the MDB. Coordinates and the template travel inside the package.</p>@endif
</div>
<form class="card" method="POST" action="{{ route('mobile-gpx.preview', $survey->client_uuid) }}">@csrf
<input type="hidden" name="survey_revision" value="{{ $survey->revision }}"><h2>Select related shared GPX</h2>
@forelse($sources as $source)<p><label><input type="checkbox" name="source_ids[]" value="{{ $source->id }}" @checked(in_array($source->id, $selected))> {{ $source->original_name }} — Group {{ $source->group_code }}, {{ $source->survey_date->format('d M Y') }}, expected {{ $source->expected_transformers }} transformers</label></p>@empty<p>No GPX is available for this team's survey dates. Upload it from the GPX linking list. Set the team two-digit group code under Teams & Assignments; the matching GPX prefix is derived automatically.</p>@endforelse
<button class="btn btn-primary" @disabled($sources->isEmpty())>Preview waypoint matches</button></form>
@php
    $displayReport = $report ?? $current?->report;
@endphp
@if($displayReport)
<section class="card"><h2>Waypoint matching report</h2>
@foreach($displayReport['issues'] as $issue)<p><strong>{{ $issue }}</strong></p>@endforeach
@foreach($displayReport['warnings'] as $warning)<p>{{ $warning }}</p>@endforeach
@php
    $locations = collect($displayReport['mapping'])->filter(fn ($m) => $m['point'])->values();
@endphp
@if($locations->isNotEmpty())
@php
    $minLat = $locations->min('point.latitude'); $maxLat = $locations->max('point.latitude');
    $minLon = $locations->min('point.longitude'); $maxLon = $locations->max('point.longitude');
    $cosLat = max(0.01, cos(deg2rad(($minLat + $maxLat) / 2)));
    $scale = 280 / max(0.000001, $maxLat - $minLat, ($maxLon - $minLon) * $cosLat);
    $xy = fn ($p) => [420 + ($p['longitude'] - ($minLon + $maxLon) / 2) * $cosLat * $scale, 175 - ($p['latitude'] - ($minLat + $maxLat) / 2) * $scale];
@endphp
<h3>Matched locations — north up</h3>
<svg viewBox="0 0 840 350" role="img" aria-label="Relative locations of matched survey waypoints and their explicit S/E connections" style="width:100%;max-height:350px;background:#f3f7fa;border:1px solid #cedbe5;border-radius:8px">
<text x="20" y="25" fill="#334155">N ↑</text>
@foreach(\App\Support\FieldSurveyPairs::groups($survey->rows) as $pair)
@php
    $s = collect($pair)->first(fn ($i) => ($survey->rows[$i]['se'] ?? '') === 'S');
    $e = collect($pair)->first(fn ($i) => ($survey->rows[$i]['se'] ?? '') === 'E');
    $from = $s !== null ? ($displayReport['mapping'][$s]['point'] ?? null) : null;
    $to = $e !== null ? ($displayReport['mapping'][$e]['point'] ?? null) : null;
@endphp
@if($from && $to)@php [$x1, $y1] = $xy($from); @endphp @php [$x2, $y2] = $xy($to); @endphp<line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke="#2563eb" stroke-width="2"/>@endif
@endforeach
@foreach($locations as $location)@php [$x, $y] = $xy($location['point']); @endphp<circle cx="{{ $x }}" cy="{{ $y }}" r="5" fill="#0f766e"/><text x="{{ $x + 8 }}" y="{{ $y - 8 }}" font-size="12" fill="#0f172a">{{ $location['point']['name'] }} ({{ $location['row_index'] + 1 }} {{ $location['se'] }})</text>@endforeach
</svg><p>Use the location links below to review geographic context. Lines follow the entered S/E pairs.</p>
@endif
<div style="overflow:auto"><table><thead><tr><th>Row / S/E</th><th>Complete survey waypoint</th><th>GPX source / point</th><th>Recording time (Pakistan)</th><th>Coordinates</th><th>Result</th></tr></thead><tbody>
@foreach($displayReport['mapping'] as $mapped)<tr><td>{{ $mapped['row_index'] + 1 }} / {{ $mapped['se'] }}</td><td>{{ $mapped['complete_waypoint'] ?: 'Invalid identity' }}</td><td>{{ $mapped['point']['filename'] ?? 'Starting-coordinate policy' }}<br>{{ $mapped['point']['name'] ?? '' }}</td><td>{{ !empty($mapped['point']['recorded_at']) ? \Carbon\CarbonImmutable::parse($mapped['point']['recorded_at'], 'UTC')->timezone('Asia/Karachi')->format('d M Y H:i:s') : 'Not recorded' }}</td><td>@if($mapped['point'])<a target="_blank" rel="noopener noreferrer" href="https://www.openstreetmap.org/?mlat={{ $mapped['point']['latitude'] }}&amp;mlon={{ $mapped['point']['longitude'] }}#map=17/{{ $mapped['point']['latitude'] }}/{{ $mapped['point']['longitude'] }}">{{ $mapped['point']['latitude'] }}, {{ $mapped['point']['longitude'] }}</a>@endif</td><td>{{ $mapped['status'] }}</td></tr>@endforeach
</tbody></table></div></section>
@if($report && !$report['issues'])
<form class="card" method="POST" action="{{ route('mobile-gpx.confirm', $survey->client_uuid) }}">@csrf
<input type="hidden" name="survey_revision" value="{{ $survey->revision }}"><input type="hidden" name="preview_sha256" value="{{ $report['preview_sha256'] }}">@foreach($selected as $id)<input type="hidden" name="source_ids[]" value="{{ $id }}">@endforeach
<label>Session confirmation / warning explanation<textarea name="confirmation_note" maxlength="4000" @required(count($report['warnings']) > 0)>{{ old('confirmation_note') }}</textarea></label>
<p><label><input type="checkbox" name="confirmed" value="1" required> I checked this transformer, group, dates and matched locations against the surveyor's GPS session, and resolved the warnings above.</label></p><button class="btn btn-primary">Confirm GPX linkage</button>
</form>@endif
@endif
@endsection
