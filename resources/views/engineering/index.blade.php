@extends('layouts.app')
@section('title', 'Engineering Configuration')
@section('content')
<div class="page-head"><div><h1>Engineering Configuration</h1><p>Set up the approved engineering standard once for each project. All its MDB files inherit the same settings.</p></div><a class="btn btn-light" href="{{ route('engineering.library') }}">Engineering defaults library</a></div>
<section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Project</th><th>Configuration</th><th>Last approved</th><th></th></tr></thead><tbody>
@forelse($projects as $project)
@php($configuration = $configurations->get($project->id))
<tr><td>{{ $project->name }} <small>{{ $project->code }}</small></td><td>{{ $configuration?->approved_by ? 'Approved · revision '.$configuration->revision : ($configuration ? 'Draft · revision '.$configuration->revision.' · review required' : 'Setup required') }}</td><td>{{ $configuration?->approved_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? '—' }}</td><td><a class="btn btn-light" href="{{ route('engineering.edit', $project) }}">{{ $configuration ? 'Review / update' : 'Set up' }}</a></td></tr>
@empty<tr><td colspan="4">Create a project in Master Data before configuring engineering settings.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
