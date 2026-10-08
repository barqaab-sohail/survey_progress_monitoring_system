@extends('layouts.app')
@section('title', 'MDB Verification Dashboard')
@section('content')
<div class="page-head">
    <div>
        <h1>MDB Verification Dashboard</h1>
        <p>Review submitted MDB files. Return any issue to the MDB user for correction.</p>
    </div>
    <div class="actions">
        <a class="btn btn-light" href="{{ route('mdb-verification.history') }}">Review history</a>
        <a class="btn btn-primary" href="{{ route('mdb-verification.index') }}">Verify MDB files</a>
    </div>
</div>
<section class="grid kpi-grid">
    @foreach(['submitted' => ['Awaiting verification', ''], 'verified' => ['MDB files verified', 'accent-green'], 'returned' => ['Returned for correction', 'accent-red'], 'verified_today' => ['Verified today by your organization', 'accent-amber']] as $key => [$label, $accent])
    <div class="card kpi {{ $accent }}"><i class="kpi-accent"></i><div class="kpi-label">{{ $label }}</div>@if(in_array($key, ['submitted', 'returned']))<a class="kpi-value" href="{{ $key === 'submitted' ? route('mdb-verification.index') : route('progress.queue', ['stage' => 'mdb_returned']) }}">{{ number_format($summary[$key]) }}</a>@else<div class="kpi-value">{{ number_format($summary[$key]) }}</div>@endif</div>
    @endforeach
</section>
<div class="section-title"><h2>MDB files awaiting verification</h2><span>{{ number_format($summary['pending_rows']) }} submitted feeder rows</span></div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Entry date</th><th>Feeder</th><th>MDB files</th><th>Submitted by</th><th>Files</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($reviewItems as $item)
        <tr>
            <td>{{ $item->entry->entry_date->format('d M Y') }}</td>
            <td><strong>{{ $item->feeder->feeder_code }}</strong><br><small>{{ $item->feeder->feeder_name }}</small></td>
            <td>{{ number_format($item->mdb_files_created) }}</td>
            <td>{{ $item->entry->enteredBy->name }}</td>
            <td>@if($item->drive_url)<a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="{{ $item->drive_url }}">Open MDB files</a>@else<span class="muted">No file link</span>@endif</td>
            <td><a class="btn btn-sm btn-primary" href="{{ route('mdb-verification.index', ['feeder_id' => $item->feeder_id]) }}">Review</a></td>
        </tr>
        @empty
        <tr><td colspan="6" class="empty">No MDB files are awaiting verification.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
