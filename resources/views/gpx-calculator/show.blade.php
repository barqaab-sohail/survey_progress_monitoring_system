@extends('layouts.app')
@section('title', 'GPX Length Calculator & KMZ Export')
@push('styles')<link rel="stylesheet" href="{{ asset('css/gpx-calculator.css') }}">@endpush
@section('content')
@php($settings = array_replace($calculation->settings, old()))
<div class="page-head"><div><h1>GPX Length Calculator &amp; KMZ Export</h1><p>{{ $calculation->file_name }} &middot; {{ count($calculation->source_data['points']) }} points &middot; {{ count($calculation->source_data['groups']) }} source groups</p></div><a class="btn btn-light" href="{{ route('gpx-calculator.index') }}">All calculations / upload</a></div>
@if(!empty($calculation->source_data['survey']['verified']))
<div class="card gpx-panel"><strong>Verified transformer {{ $calculation->source_data['survey']['transformer_code'] }}</strong><p>Batch {{ $calculation->source_data['survey']['batch_id'] }} ? Approved revision {{ $calculation->source_data['survey']['revision'] }}. This calculation retains that revision's coordinates and connections. Create a new calculation to use a later approval.</p></div>
@endif
<form id="gpx-calculator" method="POST" action="{{ route('gpx-calculator.update', $calculation) }}" data-preview-url="{{ route('gpx-calculator.preview', $calculation) }}">
    @csrf
    @foreach(['include_elevation', 'include_sag', 'connections_confirmed', 'supports_confirmed'] as $booleanField)
        <input type="hidden" name="{{ $booleanField }}" value="0">
    @endforeach
    <section class="card gpx-panel">
        <h2>1. Review connectivity and span endpoints</h2>
        @if(!empty($calculation->source_data['survey']['verified']))
        <p>Connections below come from the approved S/E records and are fixed for this calculation. Elevation and sag remain optional. To change a connection, correct and reverify the survey, then create a new calculation.</p>
        @endif
        <p>Each line below defines one separate chain or branch using point IDs, for example <code>p1, p2, p3</code>. Consecutive IDs define spans. A branch can start at a shared support: <code>p2, p4</code> on a new line. Define shared spans once.</p>
        <p>Without explicit sequences, only adjacent points within the same GPX route or track segment are connected. Standalone waypoints remain unconnected. Custom route/track sequences must stay in the same segment and original order; omitted intermediate samples do not create spans.</p>
        <label for="span-sequences">Span sequences (one chain or branch per line)</label>
        <textarea id="span-sequences" name="span_sequences" rows="5" maxlength="150000" spellcheck="false" @readonly(!empty($calculation->source_data['survey']['verified'])) placeholder="p1, p2, p3&#10;p2, p4">{{ $settings['span_sequences'] ?? '' }}</textarea>
        <div class="actions"><button class="btn btn-light" type="button" id="gpx-copy-segments" @disabled(!empty($calculation->source_data['survey']['verified']))>Copy route/track sequences for review</button><small>Standalone waypoints are never auto-sequenced.</small></div>
        <label class="gpx-check"><input type="checkbox" name="connections_confirmed" value="1" @checked($settings['connections_confirmed'] ?? false)> I have checked these sequences, including branch connections and separate segments.</label>
        <label class="gpx-check"><input type="checkbox" name="supports_confirmed" value="1" @checked($settings['supports_confirmed'] ?? false)> Every consecutive pair is an actual support-to-support span; intermediate GPS samples have been removed from these sequences.</label>
        <p class="muted">Sag is available only after both confirmations. Editing sequences clears the confirmations and turns sag off.</p>
        <details><summary>Surveyed points and source groups</summary>
            <div class="table-wrap gpx-points"><table class="table"><thead><tr><th>Point ID</th><th>Name</th><th>Source / segment</th><th>Latitude</th><th>Longitude</th><th>Elevation (m)</th><th>Validation</th></tr></thead><tbody>
                @foreach($calculation->source_data['points'] as $point)
                <tr><td><code>{{ $point['id'] }}</code></td><td>{{ $point['name'] }}</td><td>{{ $point['group'] }} ({{ $point['kind'] }})</td><td>{{ $point['lat'] ?? 'Invalid' }}</td><td>{{ $point['lon'] ?? 'Invalid' }}</td><td>{{ $point['elevation'] ?? 'Missing' }}</td><td>{{ ! $point['valid'] ? 'Invalid coordinates' : ($point['elevation'] === null ? 'Missing elevation' : 'Valid') }}</td></tr>
                @endforeach
            </tbody></table></div>
        </details>
    </section>
    <section class="card gpx-panel">
        <h2>2. Calculation options</h2>
        <div class="gpx-fields">
            <div><label class="gpx-check"><input type="checkbox" name="include_elevation" value="1" @checked($settings['include_elevation'] ?? false)> Include Elevation</label><p class="muted">Use each span's horizontal distance and elevation difference for its straight-line slope length.</p></div>
            <div><label class="gpx-check"><input type="checkbox" name="include_sag" value="1" @checked($settings['include_sag'] ?? false)> Include Sag</label><p class="muted">Estimate conductor length for each confirmed span. Sag is the maximum vertical drop below the straight chord between its supports.</p></div>
            <label>Missing elevations, when elevation is enabled
                <select name="missing_elevation"><option value="">Require a choice for affected spans</option><option value="horizontal" @selected(($settings['missing_elevation'] ?? '') === 'horizontal')>Use horizontal distance for affected spans</option><option value="exclude" @selected(($settings['missing_elevation'] ?? '') === 'exclude')>Exclude affected spans from the total</option></select>
            </label>
            <label>Default sag (m)<input type="number" name="default_sag" min="0" max="1000" step="any" value="{{ $settings['default_sag'] ?? '' }}" placeholder="Enter sag; zero is allowed"></label>
            <label>Warn for spans longer than (m)<input type="number" name="long_span_m" min="1" max="100000" step="any" value="{{ $settings['long_span_m'] ?? 500 }}" required></label>
        </div>
        <details><summary>Calculation method, assumptions and export geometry</summary>
            <p>{{ \App\Services\Gpx\Calculator::DETAILS }}</p>
            <p>The horizontal total includes all defined spans with valid coordinates. The elevation/straight and final totals include only calculated spans; explicit exclusions are reported. Blocked spans prevent a final total and KMZ export.</p>
            <p>References: <a href="https://www.ngs.noaa.gov/PUBS_LIB/inverse.pdf" target="_blank" rel="noopener">Vincenty geodesic method</a>, <a href="https://www.rd.usda.gov/files/UEP_Bulletin_1724E-152.pdf" target="_blank" rel="noopener">USDA overhead conductor mechanics</a>, <a href="https://developers.google.com/kml/documentation/altitudemode" target="_blank" rel="noopener">KML altitude modes</a>.</p>
        </details>
    </section>
    <section class="card gpx-panel">
        <h2>3. Results</h2>
        <p id="gpx-preview-status" role="status" aria-live="polite">Showing saved calculation. Changes recalculate automatically; save to retain them.</p>
        <div id="gpx-preview-error" class="alert alert-danger" role="alert" hidden></div>
        <div id="gpx-summary" class="gpx-summary"></div>
        <p id="gpx-result-label"></p>
        <details id="gpx-warnings"><summary>Warnings <span id="gpx-warning-count"></span></summary><ul id="gpx-warning-list"></ul></details>
        <div class="table-wrap"><table class="table gpx-span-table"><thead><tr><th>Span / source</th><th>Start support / point</th><th>End support / point</th><th>Horizontal (m)</th><th>Elevation difference (m)</th><th>Straight-line (m)</th><th>Applied sag / override (m)</th><th>Final (m)</th><th>Status</th></tr></thead><tbody id="gpx-span-rows"></tbody></table></div>
        <noscript><p>JavaScript is needed for live previews and the span table. Enable JavaScript, or use Save calculation to calculate on the server.</p></noscript>
    </section>
    <section class="card gpx-panel"><h2>4. Save and export</h2>
        @include('gpx-calculator.association')
        <div class="actions"><button class="btn btn-primary" type="submit" name="action" value="save">Save calculation</button><button class="btn btn-light" id="gpx-export" type="submit" name="action" value="export" @disabled(! $calculation->result['complete'])>Export KMZ</button></div>
        <p class="muted">Export recalculates and saves the current form settings. KMZ includes doc.kml, surveyed points, separate span lines, full-precision calculation attributes and warnings. Sag is shown in attributes only. Lengths are for one route/conductor; no phase multiplier.</p>
    </section>
</form>
<script id="gpx-initial" type="application/json">{!! json_encode(['source' => $calculation->source_data, 'settings' => $settings, 'result' => $calculation->result], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
@endsection
@push('scripts')<script src="{{ asset('js/gpx-calculator.js') }}" defer></script>@endpush
