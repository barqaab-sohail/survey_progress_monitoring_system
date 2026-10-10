@php($branding = app(\App\Support\ProjectBranding::class))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1f3a">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>@yield('title', 'Dashboard') Â· {{ $branding->projectName() }}</title>
    <link rel="icon" type="image/png" href="{{ $branding->faviconUrl() }}">
    <link rel="apple-touch-icon" href="{{ $branding->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=20261009-mdb-sidebar-fixed">
    @stack('styles')
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark brand-logo-mark"><img src="{{ $branding->faviconUrl() }}" alt=""></span>
            <span class="brand-copy"><strong>{{ $branding->projectName() }}</strong><small>{{ auth()->user()->hasRole('survey_team_leader') ? 'Survey Operations' : 'Survey Â· MDB Â· Verification' }}</small></span>
        </a>
        @if(request()->routeIs('mdb-workflow.*'))
            <button class="btn btn-sm logout-btn sidebar-collapse-toggle" id="sidebar-collapse-toggle" type="button" aria-controls="primary-navigation" aria-expanded="true" title="Hide left navigation and use the full page width">Hide menu</button>
        @endif
        <button class="btn btn-sm logout-btn nav-toggle" type="button" aria-label="Open navigation" onclick="document.querySelector('.sidebar').classList.toggle('open')">Menu</button>
        <div class="user-area">
            <a href="{{ route('profile.edit') }}" aria-label="My Profile"><x-user-avatar :user="auth()->user()" /></a>
            <div class="user-meta"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role->label() }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-sm logout-btn" type="submit">Sign out</button></form>
        </div>
    </header>
    <div class="workspace">
        <aside class="sidebar" id="primary-navigation">
            <a class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}">My Profile</a>
            @if(config('services.google_drive.enabled'))
                <a class="nav-link {{ request()->routeIs('google-drive.*') ? 'active' : '' }}" href="{{ route('google-drive.index') }}">Google Drive</a>
            @endif
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Overview Dashboard</a>
            @can('mdb-workflow.view')
                <a class="nav-link {{ request()->routeIs('mdb-workflow.*') ? 'active' : '' }}" href="{{ route('mdb-workflow.index') }}">MDB Creation &amp; Verification</a>
            @endcan
            @if(auth()->user()->hasAnyRole(['survey_team_leader','super_admin','project_manager','management_viewer']))
                <a class="nav-link {{ request()->routeIs('survey-progress.*') ? 'active' : '' }}" href="{{ route('survey-progress.index') }}">Automatic Survey Progress</a>
                <a class="nav-link {{ request()->routeIs('field-surveys.*') ? 'active' : '' }}" href="{{ route('field-surveys.index') }}">Mobile Field Surveys</a>
            @endif
            @if(auth()->user()->hasAnyRole(['survey_team_leader','super_admin']))
                <div class="nav-label">Survey</div>
                @unless(config('survey_progress.automatic'))<a class="nav-link {{ request()->routeIs('survey.create') ? 'active' : '' }}" href="{{ route('survey.create') }}">Add Daily Survey</a>@endunless
                <a class="nav-link {{ request()->routeIs('survey.index') ? 'active' : '' }}" href="{{ route('survey.index') }}">My Survey Entries</a>
                <a class="nav-link {{ request()->routeIs('survey.returned') ? 'active' : '' }}" href="{{ route('survey.returned') }}">Returned Entries</a>
            @endif
            @if(auth()->user()->hasAnyRole(['mdb_team_user','super_admin']))
                <div class="nav-label">MDB workflow</div>
                <a class="nav-link {{ request()->routeIs('verification.*') ? 'active' : '' }}" href="{{ route('verification.index') }}">Verify Survey Entries</a>
                <a class="nav-link {{ request()->routeIs('mdb.create') ? 'active' : '' }}" href="{{ route('mdb.create') }}">Add Daily MDB</a>
                <a class="nav-link {{ request()->routeIs('mdb.index') ? 'active' : '' }}" href="{{ route('mdb.index') }}">MDB History</a>
                <a class="nav-link {{ request()->routeIs('mdb.returned') ? 'active' : '' }}" href="{{ route('mdb.returned') }}">Returned MDB Entries</a>
            @endif
            @if(auth()->user()->hasRole('super_admin'))
                <a class="nav-link {{ request()->routeIs('field-survey-test.*') ? 'active' : '' }}" href="{{ route('field-survey-test.index') }}">Android Survey Web Test</a>
                <a class="nav-link {{ request()->routeIs('mdb-builder.*') ? 'active' : '' }}" href="{{ route('mdb-builder.index') }}">Create Transformer MDB</a>
            @endif
            @if(auth()->user()->hasAnyRole(['survey_team_leader','mdb_team_user','super_admin','project_manager']))
                <div class="nav-label">Reference data</div>
                <a class="nav-link {{ request()->routeIs('transformers.*') ? 'active' : '' }}" href="{{ route('transformers.index') }}">Transformer GIS Data</a>
            @endif
            @if(auth()->user()->hasAnyRole(['project_manager','super_admin']))
                <div class="nav-label">Control centre</div>
                <a class="nav-link" href="{{ url('/admin') }}">Filament Administration</a>
            @endif
            @if(auth()->user()->hasAnyRole(['mdb_processing_user','super_admin']))
                <div class="nav-label">MDB verification</div>
                <a class="nav-link {{ request()->routeIs('mdb-verification.index') ? 'active' : '' }}" href="{{ route('mdb-verification.index') }}">Verify MDB Files</a>
                <a class="nav-link {{ request()->routeIs('mdb-verification.history') ? 'active' : '' }}" href="{{ route('mdb-verification.history') }}">MDB Review History</a>
            @endif
            @if(auth()->user()->hasRole('super_admin'))
                <div class="nav-label">Administration</div>
                <a class="nav-link {{ request()->routeIs('admin.master.*') ? 'active' : '' }}" href="{{ route('admin.master.index') }}">Master Data & Import</a>
                <a class="nav-link {{ request()->routeIs('admin.teams.*') ? 'active' : '' }}" href="{{ route('admin.teams.index') }}">Teams & Assignments</a>
                <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}">Users</a>
                <a class="nav-link {{ request()->routeIs('admin.organizations.*') ? 'active' : '' }}" href="{{ route('admin.organizations.index') }}">Organizations</a>
                <a class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}" href="{{ route('admin.audit.index') }}">Audit Log</a>
            @endif
            @if(auth()->user()->hasAnyRole(['super_admin','project_manager','management_viewer']))
                <div class="nav-label">Reporting</div>
                <a class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}">Progress Reports</a>
            @endif
        </aside>
        <main class="main">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
            <footer><a href="{{ route('privacy-policy') }}">Privacy Policy</a> | <a href="{{ route('terms-of-service') }}">Terms of Service</a></footer>
        </main>
    </div>
</div>
<script>
document.addEventListener('click', e => { if (innerWidth <= 820 && !e.target.closest('.sidebar') && !e.target.closest('.nav-toggle')) document.querySelector('.sidebar')?.classList.remove('open'); });
const sidebarCollapseButton = document.getElementById('sidebar-collapse-toggle');
const workspace = document.querySelector('.workspace');
const sidebarPreferenceKey = 'mdb-workflow:sidebar-collapsed';
function setSidebarCollapsed(collapsed, remember = true) {
    if (!sidebarCollapseButton || !workspace) return;
    const desktopCollapsed = collapsed && innerWidth > 820;
    workspace.classList.toggle('sidebar-collapsed', desktopCollapsed);
    sidebarCollapseButton.textContent = desktopCollapsed ? 'Show menu' : 'Hide menu';
    sidebarCollapseButton.title = desktopCollapsed ? 'Show left navigation' : 'Hide left navigation and use the full page width';
    sidebarCollapseButton.setAttribute('aria-expanded', String(!desktopCollapsed));
    if (remember) try { localStorage.setItem(sidebarPreferenceKey, collapsed ? '1' : '0'); } catch (_) {}
}
if (sidebarCollapseButton) {
    let collapsed = false;
    try { collapsed = localStorage.getItem(sidebarPreferenceKey) === '1'; } catch (_) {}
    setSidebarCollapsed(collapsed, false);
    sidebarCollapseButton.addEventListener('click', () => setSidebarCollapsed(!workspace.classList.contains('sidebar-collapsed')));
    window.addEventListener('resize', () => {
        let preferred = false;
        try { preferred = localStorage.getItem(sidebarPreferenceKey) === '1'; } catch (_) {}
        setSidebarCollapsed(preferred, false);
    });
}
if ('serviceWorker' in navigator) window.addEventListener('load',()=>navigator.serviceWorker.register('{{ asset('service-worker.js') }}'));
</script>
@if(session('cleared_draft'))
<script>try { sessionStorage.removeItem(@json(session('cleared_draft'))); } catch (_) {}</script>
@endif
@stack('scripts')
</body>
</html>
