@php($branding = app(\App\Support\ProjectBranding::class))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ $branding->appName() }}</title>
    <link rel="icon" type="image/png" href="{{ $branding->faviconUrl() }}">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #24312b; background: #fff; font: 16px/1.75 Arial, sans-serif; letter-spacing: 0; }
        a { color: #075c38; text-underline-offset: 3px; overflow-wrap: anywhere; }
        header { border-bottom: 1px solid #dce3df; background: #f5f8f6; }
        .container { max-width: 900px; margin: auto; padding: 24px; }
        .identity { display: flex; align-items: center; gap: 18px; }
        .identity img { width: 80px; height: 80px; object-fit: contain; flex-shrink: 0; }
        .identity strong { font-size: 18px; line-height: 1.5; overflow-wrap: anywhere; }
        nav { display: flex; flex-wrap: wrap; gap: 12px 24px; margin-top: 20px; }
        main.container { padding-top: 36px; padding-bottom: 48px; }
        h1 { font-size: 32px; line-height: 1.3; margin: 0 0 8px; }
        h2 { font-size: 21px; line-height: 1.4; margin: 28px 0 8px; }
        p { margin: 0 0 16px; }
        li { margin-bottom: 8px; }
        .date { color: #59685f; }
        footer { border-top: 1px solid #dce3df; font-size: 14px; }
        @media (max-width: 480px) { .container { padding: 20px; } .identity { align-items: flex-start; } .identity img { width: 56px; height: 56px; } h1 { font-size: 28px; } }
    </style>
</head>
<body>
<header><div class="container">
    <div class="identity"><img src="{{ $branding->logoUrl() }}" alt="BARQAAB project logo"><div><strong>{{ $branding->appName() }}</strong><br>{{ $branding->projectName() }}</div></div>
    <nav aria-label="Public navigation">
        <a href="{{ route('login') }}">Sign in</a>
        <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
        <a href="{{ route('terms-of-service') }}">Terms of Service</a>
    </nav>
</div></header>
<main class="container">
    <h1>@yield('title')</h1>
    <p class="date">Effective date: 5 October 2026</p>
    @yield('content')
</main>
<footer><div class="container">BARQAAB Consulting Services | HAZECO project monitoring portal</div></footer>
</body>
</html>
