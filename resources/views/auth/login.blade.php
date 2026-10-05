@php($branding = app(\App\Support\ProjectBranding::class))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#075c38">
    <title>Sign in · {{ $branding->projectName() }}</title>
    <link rel="icon" type="image/png" href="{{ $branding->faviconUrl() }}">
    <link rel="apple-touch-icon" href="{{ $branding->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=20261004-1">
</head>
<body class="auth-body">
<main class="auth-page auth-login-page">
    <section class="auth-brand auth-brand-showcase">
        <div class="auth-brand-orb auth-brand-orb-one"></div>
        <div class="auth-brand-orb auth-brand-orb-two"></div>
        <div class="auth-logo-frame">
            <img src="{{ $branding->logoUrl() }}" alt="BARQAAB project logo" class="auth-project-logo">
        </div>
        <div class="auth-brand-copy">
            <span class="auth-kicker">HAZECO project monitoring portal</span>
            <h1>{{ $branding->projectName() }}</h1>
            <p>One secure operational view for field survey, verification, MDB creation, and management decisions.</p>
            <div class="auth-feature-row" aria-label="Portal capabilities">
                <span>Survey progress</span>
                <span>Verified records</span>
                <span>Management insights</span>
            </div>
        </div>
        <p class="auth-brand-footer">BARQAAB Consulting Services</p>
    </section>

    <section class="auth-form">
        <form class="auth-box auth-login-box" method="POST" action="{{ route('login.store') }}">
            @csrf
            <div class="auth-mobile-logo"><img src="{{ $branding->logoUrl() }}" alt="BARQAAB project logo"></div>
            <span class="eyebrow">Secure project access</span>
            <h2>Welcome back</h2>
            <p class="muted">Enter your authorized project account details.</p>

            @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
            @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif

            <div class="field auth-field">
                <label for="email">Email address</label>
                <div class="auth-input-wrap">
                    <span aria-hidden="true">@</span>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="name@example.com" required autofocus>
                </div>
            </div>
            <div class="field auth-field">
                <label for="password">Password</label>
                <div class="auth-input-wrap">
                    <span aria-hidden="true">●</span>
                    <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                    <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <div class="auth-options">
                <label class="remember-option"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span></label>
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
            <button class="btn btn-primary auth-submit" type="submit">Sign in to dashboard <span aria-hidden="true">→</span></button>
            <p class="auth-security-note">Authorized users only · Activity is securely recorded.</p>
            <p class="auth-security-note"><a href="{{ route('privacy-policy') }}">Privacy Policy</a> | <a href="{{ route('terms-of-service') }}">Terms of Service</a></p>
        </form>
    </section>
</main>
<script>
document.querySelector('.password-toggle')?.addEventListener('click', event => {
    const input = document.getElementById('password');
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    event.currentTarget.textContent = reveal ? 'Hide' : 'Show';
    event.currentTarget.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    event.currentTarget.setAttribute('aria-pressed', reveal ? 'true' : 'false');
});
</script>
</body>
</html>
