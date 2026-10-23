@extends('layouts.app')
@section('title', 'GPX Length Calculator & KMZ Export')
@push('styles')<link rel="stylesheet" href="{{ asset('css/gpx-calculator.css') }}">@endpush
@section('content')
<div class="page-head"><div><h1>GPX Length Calculator &amp; KMZ Export</h1><p>Measure surveyed routes and confirmed support spans, with optional elevation and sag.</p></div></div>
<form class="card gpx-panel" method="POST" action="{{ route('gpx-calculator.store') }}" enctype="multipart/form-data">
    @csrf
    <h2>Upload a GPX file</h2>
    <label>GPX file (up to 10 MB)<input type="file" name="gpx" accept=".gpx,application/gpx+xml,application/xml,text/xml" required></label>
    <p class="muted">Routes and track segments retain their order and boundaries. Standalone waypoints need explicit span sequences. Elevation and sag are both off for every new upload.</p>
    @include('gpx-calculator.association')
    <button class="btn btn-primary" type="submit">Upload and inspect points</button>
</form>
<section class="card gpx-panel"><h2>Saved calculations</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>File</th><th>Project / feeder</th><th>Included spans</th><th>Final length</th><th>Updated</th></tr></thead><tbody>
    @forelse($calculations as $record)
        <tr><td><a href="{{ route('gpx-calculator.show', $record) }}">{{ $record->file_name }}</a></td><td>{{ $record->project?->code ?? 'Unlinked' }} / {{ $record->feeder?->feeder_code ?? '—' }}</td><td>{{ $record->result['valid_spans'] }} / {{ $record->result['span_count'] }}</td><td>{{ $record->result['final_m'] === null ? 'Needs review' : number_format($record->result['final_m'], 3).' m' }}</td><td>{{ $record->updated_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</td></tr>
    @empty<tr><td colspan="5">Upload a GPX to start a calculation.</td></tr>@endforelse
    </tbody></table></div>{{ $calculations->links() }}
</section>
@endsection
@push('scripts')<script src="{{ asset('js/gpx-calculator.js') }}" defer></script>@endpush
