<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · HAZECO T&D Losses</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body><main class="auth-page">
    <section class="auth-brand"><div class="brand-mark">T&D</div><h1>Minimum input.<br>Maximum visibility.</h1><p>One operational view from field survey through verified MDB processing for the HAZECO T&D Losses Project.</p></section>
    <section class="auth-form"><form class="auth-box" method="POST" action="{{ route('login.store') }}">@csrf
        <h2>Welcome back</h2><p class="muted">Sign in with your project account.</p>
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div>
        <div class="field" style="margin-top:16px"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required></div>
        <label style="display:flex;gap:8px;margin:16px 0 22px"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="btn btn-primary" style="width:100%" type="submit">Sign in</button>
    </form></section>
</main></body></html>
