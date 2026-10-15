@extends('layouts.app')
@section('title', 'Google Drive')
@section('content')
<div class="page-header"><h1>Google Drive</h1></div>
@if(auth()->user()->hasRole('super_admin'))
<section class="card"><h2>Application file storage</h2>
    <p>Automatic storage: <strong>{{ config('drive_storage.enabled') ? 'Enabled' : 'Disabled' }}</strong>.
    {{ $storageConnection ? 'Connected: '.$storageConnection->account_email : 'No storage account connected.' }}</p>
    <p>{{ $storagePending }} file(s) awaiting transfer. Failed transfers retain a private temporary original and retry automatically while the scheduler and queue worker run.</p>
    @if($configured)<form method="POST" action="{{ route('google-drive.connect') }}">@csrf
        <input type="hidden" name="application_storage" value="1">
        <button class="btn btn-primary">{{ $storageConnection ? 'Reconnect storage account' : 'Connect application storage account' }}</button>
    </form>@endif
    <p class="muted">Use the organization's storage account. Personal Drive disconnection below does not disconnect application storage.</p>
</section>
@endif
<h2>Personal Drive imports</h2>
<p>Status: <strong>{{ $connected ? 'Connected' : 'Not connected' }}</strong></p>
@if(!$configured)
    <div class="alert alert-danger">Google Drive is awaiting administrator configuration.</div>
@else
    <form method="POST" action="{{ route('google-drive.connect') }}">
        @csrf
        <button class="btn btn-primary" type="submit">{{ $connected ? 'Reconnect Google Drive' : 'Connect Google Drive' }}</button>
    </form>
@endif
@if($connected)
    <form method="POST" action="{{ route('google-drive.disconnect') }}">
        @csrf
        @method('DELETE')
        <button class="btn" type="submit">Disconnect Google Drive</button>
    </form>
@endif
@endsection
