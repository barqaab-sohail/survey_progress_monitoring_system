<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset password · HAZECO T&D Losses</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body><main class="auth-page">
<section class="auth-brand"><div class="brand-mark">T&D</div><h1>Choose a<br>new password.</h1><p>This link can reset only the account associated with the email that received it.</p></section>
<section class="auth-form"><form class="auth-box" method="POST" action="{{ route('password.update') }}">
@csrf
<input type="hidden" name="token" value="{{ $token }}">
<h2>Reset password</h2><p class="muted">Use at least 10 characters for your new password.</p>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@php($enteredEmail = old('email', $email))
<div class="field"><label for="email">Registered email address</label><input id="email" type="email" name="email" value="{{ is_string($enteredEmail) ? $enteredEmail : '' }}" autocomplete="email" maxlength="255" required></div>
<div class="field" style="margin-top:16px"><label for="password">New password</label><input id="password" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="72" required autofocus></div>
<div class="field" style="margin-top:16px"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="10" maxlength="72" required></div>
<button class="btn btn-primary" style="width:100%;margin-top:22px" type="submit">Reset password</button>
<p style="text-align:center;margin-top:16px"><a href="{{ route('password.request') }}">Request a new reset link</a></p>
</form></section>
</main></body></html>
