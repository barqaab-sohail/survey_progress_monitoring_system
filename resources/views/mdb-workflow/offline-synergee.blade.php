@extends('layouts.app')
@section('title', 'JSON to SynerGEE MDB')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>JSON to SynerGEE MDB</h1><p>Generate a network model from a verified production survey on this Windows computer.</p></div></div>
    <section class="card"><h2>1. Select the production export</h2>
        <p>In production, open the verified survey and choose <strong>Download SynerGEE JSON</strong>. This file includes the frozen coordinates, engineering settings and clean template.</p>
        <form method="POST" action="{{ route('mdb-workflow.offline-synergee.preview') }}" enctype="multipart/form-data">@csrf
            <div class="mdb-fields">
                <label>SynerGEE JSON (up to 30 MB)<input type="file" name="file" accept=".json" required></label>
                <label>Original GPX (optional, up to 10 MB)<input type="file" name="gpx" accept=".gpx"><small>Checks it against the frozen source. Embedded verified coordinates are always used.</small></label>
            </div><button class="btn btn-primary">Check survey and engineering inputs</button>
        </form>
    </section>
    @isset($package)
    <section class="card"><h2>2. Review before generation</h2>
        @php($simpleMode = \App\Services\Mdb\GenerationMode::simple($package['snapshot']['configuration'] ?? null))
        <p><strong>Mode in uploaded JSON: {{ $simpleMode ? 'ON — Simple MDB Generation' : 'OFF — Full engineering validation' }}</strong></p>
        <p>{{ $simpleMode ? 'Missing engineering settings appear as optional review notes. Only the mandatory corrections below block generation.' : 'This file uses full engineering validation. To use Simple mode, enable it in the source project, verify and approve the updated survey, and download a fresh SynerGEE JSON.' }}</p>
        <p><strong>{{ implode(', ', $package['scope']['transformer_codes']) }}</strong> &middot; Batch {{ $package['source']['batch_id'] }} &middot; Revision {{ $package['source']['revision_number'] }}</p>
        <p>Feeder: {{ $package['source']['feeder_code'] ?? '-' }} {{ $package['source']['feeder_name'] ?? '' }} &middot; {{ $package['snapshot']['template']['synergee_version'] }}</p>
        <p>Exported {{ $package['source']['exported_at'] }}. {{ $gpxChecked ? 'The selected GPX matches the frozen source.' : 'Using coordinates and elevations embedded in the verified snapshot.' }}</p>
        @if(count($issues))
        <div class="alert alert-danger"><h3>Mandatory corrections — resolve in production, then verify and export again</h3><ul>
            @foreach($issues as $issue)<li>{{ $issue['message'] }} @if(isset($issue['transformer_id']))(Transformer #{{ $issue['transformer_id'] }})@endif @if(isset($issue['source_page']))(PDF page {{ $issue['source_page'] }})@endif @if(isset($issue['section_id']))(Section #{{ $issue['section_id'] }})@endif</li>@endforeach
        </ul></div>
        @else
            <ul>@foreach($payload['tables'] as $table=>$rows)<li>{{ $table }}: {{ count($rows) }} records</li>@endforeach</ul>
            <form method="POST" action="{{ route('mdb-workflow.offline-synergee.download') }}">@csrf
                <input type="hidden" name="package" value="{{ $packageJson }}">
                <button class="btn btn-primary">Generate and download SynerGEE MDB</button>
            </form>
        @endif
        @if(count($warnings))
            <details><summary>Non-blocking review notes ({{ count($warnings) }}) — these do not prevent MDB generation</summary>
                <ul>@foreach(collect($warnings)->groupBy('message') as $message => $notes)<li>{{ $message }} @if($notes->count() > 1)({{ $notes->count() }} occurrences)@endif</li>@endforeach</ul>
            </details>
        @endif
        <p>All table values, schema and model references are read back before delivery. Open the result in the target SynerGEE installation to check connectivity and load flow. No local survey database import is performed.</p>
    </section>
    @endisset
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
