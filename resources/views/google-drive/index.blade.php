@extends('layouts.app')
@section('title', 'Google Drive')
@section('content')
<div class="page-header"><h1>Google Drive</h1></div>
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
