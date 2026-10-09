@extends('layouts.app')
@section('title', 'My Profile')
@section('content')
<div class="page-head"><div><h1>My Profile</h1><p>Upload or change your profile picture.</p></div></div>
<form class="card" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    <x-user-avatar :user="$user" :size="96" />
    <h2>{{ $user->name }}</h2><p>{{ $user->email }}</p>
    <div class="field"><label for="profile-photo">Profile picture</label><input id="profile-photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP; up to 2 MB and 4096 × 4096 pixels.</small></div>
    @if($user->profile_photo_path)<p><label><input type="checkbox" name="remove_photo" value="1"> Remove current picture</label></p>@endif
    <div class="form-footer"><span>A new upload replaces the current picture.</span><button class="btn btn-primary">Save picture</button></div>
</form>
@endsection
