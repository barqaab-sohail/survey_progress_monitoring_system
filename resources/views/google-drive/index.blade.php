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
    <h3>Existing duplicate PDF and GPX files</h3>
    @if(empty($duplicateGroups))
        <p>No confirmed duplicate PDF or GPX Drive copies were found.</p>
    @else
        @foreach($duplicateGroups as $group)
            <div class="card" style="margin:1rem 0">
                <p><strong>SHA-256:</strong> <code>{{ $group['sha256'] }}</code> · {{ $group['copies'] }} copies · estimated recoverable storage {{ number_format($group['recoverable_bytes'] / 1048576, 2) }} MB</p>
                <ul>@foreach($group['files'] as $file)
                    <li><strong>{{ $file['name'] }}</strong> · Feeder: {{ $file['feeder'] }} · Uploaded: {{ $file['uploaded_at']?->format('d M Y H:i') ?? 'Unknown' }} · Uploader: {{ $file['uploader'] }} · {{ number_format($file['bytes']) }} bytes</li>
                @endforeach</ul>
                @if($group['consistent'])
                    <form method="POST" action="{{ route('google-drive.duplicates.cleanup', $group['sha256']) }}" onsubmit="return confirm('Keep the oldest confirmed copy, repoint valid records to it, and permanently delete the other confirmed Drive copies? This cannot be undone.');">
                        @csrf
                        <label><input type="checkbox" name="confirm" value="1" required> I reviewed these files and confirm permanent cleanup.</label>
                        <button class="btn btn-danger" type="submit">Clean up confirmed duplicates</button>
                    </form>
                @else
                    <p class="alert alert-warning">Automatic cleanup is unavailable because copy size or storage-account details do not match. No files have been changed.</p>
                @endif
            </div>
        @endforeach
        <p class="muted">Cleanup keeps the oldest confirmed copy in its current Drive folder. The report never deletes files automatically.</p>
    @endif
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
