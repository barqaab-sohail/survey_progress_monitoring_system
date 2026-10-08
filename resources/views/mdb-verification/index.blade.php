@extends('layouts.app')
@section('title','MDB Pending Verification')
@section('content')
<div class="page-head"><div><h1>MDB Pending Verification</h1><p>Review each MDB file link and quantity. Verify valid work or return it with a clear reason.</p></div><span class="status status-pending">{{ $items->total() }} PENDING</span></div>
@include('components.review-aging')
@forelse($items as $item)
<article class="card">
<div class="page-head"><div><h3 style="margin:0">{{ $item->feeder->feeder_code }} · {{ $item->feeder->feeder_name }}</h3><p>{{ $item->entry->entry_date->format('d M Y') }} · {{ $item->entry->enteredBy->name }}</p></div><div class="kpi-value">{{ $item->mdb_files_created }}</div></div>
<p><strong>{{ \App\Support\ReviewAging::days($item) }} days waiting</strong> &middot; Responsible: Third-party review team @if(\App\Support\ReviewAging::days($item) >= 7)<span class="status status-pending">Overdue</span>@endif</p>
@if($item->remarks)<p>{{ $item->remarks }}</p>@endif
<div class="actions">
    @if($item->drive_url)<a target="_blank" rel="noopener" class="btn btn-light" href="{{ $item->drive_url }}">Open MDB files</a>@else<span class="muted">No Drive link provided.</span>@endif
    @if($item->entry->entered_by !== auth()->id())
    @can('verify', $item)
    <form method="POST" action="{{ route('mdb-verification.verify', $item) }}">@csrf<button class="btn btn-success" type="submit">Verify MDB</button></form>
    @endcan
    @endif
</div>
@if($item->entry->entered_by !== auth()->id())
@can('return', $item)
<form method="POST" action="{{ route('mdb-verification.return', $item) }}" style="margin-top:14px">
@csrf<input type="hidden" name="review_item_id" value="{{ $item->id }}">
<div class="form-grid"><div class="field"><label>Return reason</label><input name="reason" value="{{ (string) old('review_item_id') === (string) $item->id ? old('reason') : '' }}" minlength="5" maxlength="2000" required placeholder="Describe the issue that needs correction"></div><div class="field" style="align-self:end"><button class="btn btn-danger" type="submit">Return for correction</button></div></div>
</form>
@endcan
@endif
</article>
@empty<div class="card empty">No MDB entries are awaiting verification.</div>@endforelse
{{ $items->links() }}
@endsection
