@extends('layouts.app')
@section('title', 'HT Network Updates')
@section('content')
@if(auth()->user()->hasAnyRole(['super_admin','project_manager']))<p><a class="btn btn-primary" href="{{ route('feeder-kmz.index') }}">Feeder HT + approved LT</a></p>@endif
@if(app(\App\Services\HtNetworkWorkflow::class)->canCreateWeb(auth()->user()))<p><a class="btn btn-primary" href="{{ route('ht-updates.create') }}">New HT Network Update ? 11 kV feeder</a></p>@endif
<div class="page-head"><div><h1>HT Network Updates</h1><p>11 kV feeder additions awaiting independent HT verification. Complete HT approval before starting LT entry.</p></div><a class="btn btn-light" href="{{ route('field-surveys.index') }}">LT Transformer Surveys</a></div>
<section class="card"><div style="overflow:auto"><table class="table"><thead><tr><th>Transformer</th><th>Feeder</th><th>Surveyor</th><th>HT verification</th><th>Updated</th></tr></thead><tbody>
@forelse($updates as $update)
<tr><td><a href="{{ route('ht-updates.show', $update) }}">{{ $update->transformer_code }}</a></td><td>{{ $update->feeder->feeder_code }} · {{ $update->feeder->feeder_name }}</td><td>{{ $update->collector->name }}</td><td>{{ ucfirst($update->status) }}</td><td>{{ $update->updated_at }}</td></tr>
@empty<tr><td colspan="5">No HT Network Updates submitted.</td></tr>@endforelse
</tbody></table></div>{{ $updates->links() }}</section>
@endsection
