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
        <p>Engineering configuration revision in this JSON: <strong>{{ $package['snapshot']['configuration']['revision'] ?? 'Not recorded' }}</strong>. Changes saved in Engineering Configuration apply to a new verified export. After saving changes in the source project, verify and approve the survey again, download a fresh SynerGEE JSON, and upload it here.</p>
        @if(count($issues))
        <div class="alert alert-danger"><h3>Mandatory corrections — resolve in production, then verify and export again</h3><ul>
            @foreach($issues as $issue)<li>{{ $issue['message'] }} @if(isset($issue['transformer_id']))(Transformer #{{ $issue['transformer_id'] }})@endif @if(isset($issue['source_page']))(PDF page {{ $issue['source_page'] }})@endif @if(isset($issue['section_id']))(Section #{{ $issue['section_id'] }})@endif</li>@endforeach
        </ul></div>
        @else
            @php($transformerTypes = collect($payload['tables']['InstPrimaryTransformers'])->keyBy('UniqueDeviceId'))
            <h3>Transformer types in this export</h3>
            <div class="table-wrap"><table class="table"><thead><tr><th>Transformer</th><th>Survey capacity (kVA)</th><th>Equipment type for MDB</th></tr></thead><tbody>
                @foreach($package['snapshot']['transformers'] as $transformer)
                    @if(in_array($transformer['code'], $package['scope']['transformer_codes'], true))
                        @php($equipmentType = trim((string) ($transformerTypes->get($transformer['code'])['TransformerType'] ?? '')))
                        <tr><td>{{ $transformer['code'] }}</td><td>{{ $transformer['capacity_kva'] ?? 'Missing' }}</td><td>{{ $equipmentType !== '' ? $equipmentType : 'Missing from uploaded JSON' }}</td></tr>
                    @endif
                @endforeach
            </tbody></table></div>
            @if($transformerTypes->contains(fn ($row) => trim((string) ($row['TransformerType'] ?? '')) === ''))
                <div class="alert alert-warning">A transformer equipment type is missing from this JSON. Save the capacity-to-type mapping in the source project's Engineering Configuration, verify and approve the survey again, then download and upload a fresh SynerGEE JSON. Uploading the same file again retains its original settings.</div>
            @endif
            @if($modelReadiness)
                <h3>Model completeness: {{ $modelReadiness['complete'] ? 'listed inputs populated' : 'draft — model inputs still missing' }}</h3>
                <div class="table-wrap"><table class="table"><thead><tr><th>Model input</th><th>Populated records</th><th>Missing records</th></tr></thead><tbody>
                @foreach($modelReadiness['checks'] as $check)<tr><td>{{ $check['label'] }}</td><td>{{ $check['complete'] }} / {{ $check['total'] }}</td><td>{{ $check['missing'] }}</td></tr>@endforeach
                </tbody></table></div>
                @php($loadCheck = collect($modelReadiness['checks'])->firstWhere('label', 'Load demand and customer allocation'))
                @if(($loadCheck['missing'] ?? 0) > 0)
                    <div class="alert alert-warning">
                        <p><strong>Load demand is incomplete.</strong> Demand method in this JSON: {{ ($package['snapshot']['configuration']['load_assumptions']['mode'] ?? null) === 'category_rates' ? 'Central category rates' : (($package['snapshot']['configuration']['load_assumptions']['mode'] ?? null) === 'manual' ? 'Verified per-phase demand' : 'Not selected') }}.</p>
                        <p>In the source project's Engineering Configuration, under Load-demand mapping, choose Apply central category rates automatically and confirm the demand-method evidence. In Simple mode, select Confirm and apply Simple mode settings. Then verify and approve the survey again and upload a fresh SynerGEE JSON. Alternatively, supply verified per-phase demand using the manual method.</p>
                        <p>Category rates allocate 2 kVA per single-phase consumer and 5 kVA per three-phase consumer across connected phases. This check requires customer allocation and kVA on each connected phase; kW and kvar remain unset for this method.</p>
                    </div>
                @endif
                <p>{{ $modelReadiness['acceptance'] }} @unless($modelReadiness['complete'])Simple mode can download this draft, but missing inputs must be completed before using it for electrical analysis.@endunless</p>
            @endif
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
