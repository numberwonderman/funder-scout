<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f5f1e8">
    <title>{{ $title ?? 'Kindred' }}</title>
    <link rel="stylesheet" href="{{ asset('css/kindred.css') }}">
    <link rel="stylesheet" href="{{ asset('css/decision-support.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('research.index') }}" aria-label="Kindred home">
            <img src="{{ asset('images/kindred-logo.png') }}" alt="Kindred — People fuel possibility">
        </a>
        <nav class="primary-nav" aria-label="Primary navigation">
            <div class="nav-label">Workspace</div>
            <a class="nav-link {{ request()->routeIs('research.*') ? 'active' : '' }}" href="{{ route('research.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg><span>Research</span></a>
            <a class="nav-link {{ request()->routeIs('campaigns.*') ? 'active' : '' }}" href="{{ route('campaigns.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg><span>Campaigns</span></a>
            <a class="nav-link {{ request()->routeIs('prospects.*') ? 'active' : '' }}" href="{{ route('prospects.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19v-8l8-6 8 6v8"/><path d="M9 19v-5h6v5"/></svg><span>Prospects</span></a>
        </nav>
        <nav class="nav-bottom" aria-label="Account navigation">
            <a class="nav-link {{ request()->routeIs('organization.*') ? 'active' : '' }}" href="{{ route('organization.edit') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 20c.5-4 3-6 7-6s6.5 2 7 6"/></svg><span>Organization</span></a>
            <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg><span>Settings</span></a>
            <div class="user-card"><span class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->organization->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link logout-link" type="submit">Sign out</button></form>
        </nav>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <div class="mobile-brand">Kindred</div><span class="crumb">Institutional funder research</span>
            <div class="topbar-actions">@if(config('services.agent.demo_mode'))<span class="mode demo">Demo data</span>@else<span class="mode">Live research</span>@endif</div>
        </header>
        <main>@if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif @yield('content')</main>
    </div>
</div>
</body>
</html>
