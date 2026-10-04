@php($branding = app(\App\Support\ProjectBranding::class))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot password · {{ $branding->projectName() }}</title><link rel="icon" type="image/png" href="{{ $branding->faviconUrl() }}"><link rel="stylesheet" href="{{ asset('css/app.css') }}?v=20261004-1"></head>
<body><main class="auth-page">
<section class="auth-brand"><div class="brand-mark brand-logo-mark"><img src="{{ $branding->faviconUrl() }}" alt=""></div><h1>Recover your<br>project account.</h1><p>{{ $branding->projectName() }}<br>Use the email address registered for your account.</p></section>
<section class="auth-form"><form class="auth-box" method="POST" action="{{ route('password.email') }}">
@csrf
<h2>Forgot password?</h2><p class="muted">Enter your registered email address to request a password reset link.</p>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@php($enteredEmail = old('email', ''))
<div class="field"><label for="email">Registered email address</label><input id="email" type="email" name="email" value="{{ is_string($enteredEmail) ? $enteredEmail : '' }}" autocomplete="email" maxlength="255" required autofocus></div>
<button class="btn btn-primary" style="width:100%;margin-top:22px" type="submit">Send reset link</button>
<p style="text-align:center;margin-top:16px"><a href="{{ route('login') }}">Back to sign in</a></p>
</form></section>
</main></body></html>
