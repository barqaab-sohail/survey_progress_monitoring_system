@extends('layouts.app')
@section('title', 'JSON to MDB')
@section('content')
<div class="mdb-workflow">
    <div class="page-head"><div><h1>JSON to MDB</h1><p>Convert a transformer file downloaded from production into a Data Entry MDB.</p></div></div>
    <section class="card"><h2>1. Upload Transformer JSON</h2><p>In production, open an approved survey, choose Export Data Entry MDB, then download the JSON for one transformer.</p>
        <form method="POST" action="{{ route('mdb-workflow.offline-mdb.preview') }}" enctype="multipart/form-data">@csrf
            <label>Transformer JSON file <input type="file" name="file" accept=".json,application/json" required></label>
            <p class="mdb-note">Maximum 10 MB. Conversion does not add survey records or approvals to this computer's database.</p>
            <button class="btn btn-primary" type="submit">Upload and preview</button>
        </form>
    </section>
    @isset($package)
    <section class="card"><h2>2. Generate MDB</h2>
        <p><strong>{{ $package['snapshot']['transformers'][0]['code'] }}</strong> &middot; Source batch #{{ $package['source']['batch_id'] }} &middot; Revision {{ $package['source']['revision_number'] }}</p>
        <p>{{ count($payload['rows']) }} observations &middot; {{ count($payload['rows']) / 2 }} S/E pairs. Exported {{ $package['source']['exported_at'] }}.</p>
        <p>The file format, checksum and S/E pairs passed validation. Review the format limitations below before downloading.</p>
        <details open><summary>MDB format limitations and mappings</summary><ul>@foreach($payload['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></details>
        <form method="POST" action="{{ route('mdb-workflow.offline-mdb.download') }}">@csrf
            <input type="hidden" name="package" value="{{ $packageJson }}">
            <button class="btn btn-primary" type="submit">Generate and download MDB</button>
        </form>
    </section>
    @endisset
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css') }}">@endpush
