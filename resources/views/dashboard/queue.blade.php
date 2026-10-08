@extends('layouts.app')
@section('title', $title)
@section('content')
<div class="page-head"><div><h1>{{ $title }}</h1><p>Work matching the selected dashboard stage.</p></div><a class="btn btn-light" href="{{ route('dashboard') }}">Dashboard</a></div>
@if($aging) @include('components.review-aging') @endif
@if($items)
@forelse($items as $item)
<article class="card"><h2>{{ $item->feeder->feeder_code }} &middot; {{ $item->feeder->feeder_name }}</h2>
<p>{{ $item->entry->entry_date->format('d M Y') }} &middot; Submitted by {{ $item->entry->enteredBy->name }} &middot; Quantity: {{ $item->transformers_surveyed ?? $item->mdb_files_created }}</p>
@if($aging)<p>{{ \App\Support\ReviewAging::days($item) }} days waiting &middot; Responsible: {{ $stage === 'verification' ? 'MDB review team' : 'Third-party review team' }}</p>@endif
@if($item->return_reason)<p>Return reason: {{ $item->return_reason }}</p>@endif
<div class="actions">
@if($stage === 'verification' && auth()->user()->hasAnyRole(['super_admin','mdb_team_user']))<a class="btn btn-primary" href="{{ route('verification.index', ['feeder_id' => $item->feeder_id]) }}">Review survey</a>@endif
@if($stage === 'mdb_verification' && auth()->user()->hasAnyRole(['super_admin','mdb_processing_user']))<a class="btn btn-primary" href="{{ route('mdb-verification.index', ['feeder_id' => $item->feeder_id]) }}">Review MDB</a>@endif
@if($stage === 'mdb_returned' && auth()->user()->hasAnyRole(['super_admin','mdb_team_user']))<a class="btn btn-primary" href="{{ route('mdb.returned') }}">Correct returned MDBs</a>@endif
</div></article>
@empty<div class="card empty">No work matches this queue.</div>@endforelse
{{ $items->links() }}
@else
@forelse($feeders as $feeder)
<article class="card"><h2>{{ $feeder->feeder_code }} &middot; {{ $feeder->feeder_name }}</h2>
<p>{{ $stage === 'baseline' ? 'Transformer baseline needs verification' : 'Remaining quantity: '.number_format($stage === 'survey' ? $feeder->survey_pending : $feeder->mdb_creation_backlog) }}</p>
@if($stage === 'baseline' && auth()->user()->hasRole('super_admin'))<a class="btn btn-primary" href="{{ route('admin.master.edit', $feeder) }}">Update baseline</a>@endif
@if($stage === 'survey' && auth()->user()->hasAnyRole(['super_admin','survey_team_leader']))<a class="btn btn-primary" href="{{ route('survey.create') }}">Add daily survey</a>@endif
@if($stage === 'mdb_creation' && auth()->user()->hasAnyRole(['super_admin','mdb_team_user']))<a class="btn btn-primary" href="{{ route('mdb.create') }}">Add daily MDB</a>@endif
</article>
@empty<div class="card empty">No work matches this queue.</div>@endforelse
@endif
@endsection
