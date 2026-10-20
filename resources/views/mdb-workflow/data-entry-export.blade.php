@extends('layouts.app')
@section('title', 'Export Data Entry MDB')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>Export Data Entry MDB</h1><p>Batch #{{ $batch->id }} &middot; Approved revision {{ $payload['revision_number'] }}</p></div><a class="btn btn-light" href="{{ route('mdb-workflow.review', $batch) }}">Back to survey</a></div>
    <section class="card"><p>{{ $payload['transformer_count'] }} transformers &middot; {{ count($payload['rows']) }} observations &middot; {{ count($payload['rows']) / 2 }} S/E pairs.</p>
        <p>This intermediate PDF survey export contains the reference Field_Data (69 fields) and GPS_Points (7 fields) tables. Coordinates stay NULL and GPS_Points stays empty. It is independent of final SynerGEE generation.</p>
        <h2>Format limitations and mappings</h2>
        <ul>@foreach($payload['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
        <p>Information omitted from this format remains in the approved application snapshot. Each download is checked against the reference schema and read back field by field before delivery.</p>
        <form method="POST" action="{{ route('mdb-workflow.data-entry-export.download', $batch) }}">@csrf
            <input type="hidden" name="revision_sha256" value="{{ $payload['revision_sha256'] }}">
            <button class="btn btn-primary" type="submit">Download Data Entry MDB</button>
        </form>
    </section>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
