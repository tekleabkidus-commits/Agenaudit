<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0a1020">
    <title>@yield('title','Agent Audit') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="app">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a class="brandmark" href="{{ auth()->user()->isAdmin() ? route('admin.dashboard') : route('employee.home') }}">
                <span class="branddot">A</span>
                <span class="brand-copy">
                    <b>Agent Audit</b>
                    <small>{{ auth()->user()->isAdmin() ? 'Control Center' : 'Employee Workspace' }}</small>
                </span>
            </a>
            <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation">×</button>
        </div>

        <nav class="nav">
            @if(auth()->user()->isAdmin())
                <div class="nav-section">Overview</div>
                <a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7"/><path d="M5 9v12h14V9"/><path d="M9 21v-7h6v7"/></svg></span><span>Dashboard</span></a>
                <a class="{{ request()->routeIs('admin.transactions.*')?'active':'' }}" href="{{ route('admin.transactions.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16l-4-4"/><path d="m20 7-4 4"/><path d="M20 17H4l4 4"/><path d="m4 17 4-4"/></svg></span><span>Transactions</span></a>
                <a class="{{ request()->routeIs('admin.corrections.*')?'active':'' }}" href="{{ route('admin.corrections.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/><rect x="3" y="3" width="18" height="18" rx="5"/></svg></span><span>Approvals</span></a>
                <a class="{{ request()->routeIs('admin.credits.*')?'active':'' }}" href="{{ route('admin.credits.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 10h18"/><circle cx="16.5" cy="15" r="1"/></svg></span><span>Credit</span></a>
                <a class="{{ request()->routeIs('admin.commissions.*')?'active':'' }}" href="{{ route('admin.commissions.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 9-9 11L3 11 12 2Z"/><path d="m3 11 9 3 9-3"/></svg></span><span>Commission</span></a>
                <a class="{{ request()->routeIs('admin.reports.*')?'active':'' }}" href="{{ route('admin.reports.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-9"/><path d="M22 20H2"/></svg></span><span>Reports</span></a>

                <div class="nav-section">Master Data</div>
                <a class="{{ request()->routeIs('admin.brands.*')?'active':'' }}" href="{{ route('admin.brands.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="m12 6 5 6-5 6-5-6z"/></svg></span><span>Brands</span></a>
                <a class="{{ request()->routeIs('admin.agents.*')?'active':'' }}" href="{{ route('admin.agents.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-5 5-7 8-7s7 2 8 7"/></svg></span><span>Agents</span></a>
                <a class="{{ request()->routeIs('admin.agent-imports.*')?'active':'' }}" href="{{ route('admin.agent-imports.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 16 0-13"/><path d="m7 8 5-5 5 5"/><path d="M4 16v4h16v-4"/></svg></span><span>Agent Import</span></a>
                <a class="{{ request()->routeIs('admin.banks.*')?'active':'' }}" href="{{ route('admin.banks.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M7 9h10M7 13h10M7 17h6"/></svg></span><span>Banks</span></a>
                <a class="{{ request()->routeIs('admin.receiving-accounts.*')?'active':'' }}" href="{{ route('admin.receiving-accounts.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 10h18M7 15h4"/></svg></span><span>Receiving Accounts</span></a>

                <div class="nav-section">Administration</div>
                <a class="{{ request()->routeIs('admin.employees.*')?'active':'' }}" href="{{ route('admin.employees.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21v-2a5 5 0 0 1 10 0v2"/><circle cx="12" cy="8" r="4"/></svg></span><span>Users & Permissions</span></a>
                <a class="{{ request()->routeIs('admin.sessions.*')?'active':'' }}" href="{{ route('admin.sessions.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="m8 12 3 3 5-6"/></svg></span><span>Device Sessions</span></a>
                <a class="{{ request()->routeIs('admin.audit.*')?'active':'' }}" href="{{ route('admin.audit.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span><span>Audit Log</span></a>
                <a class="{{ request()->routeIs('admin.health.*')?'active':'' }}" href="{{ route('admin.health.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13h3l2-5 4 9 2-4h5"/><rect x="2" y="3" width="20" height="18" rx="4"/></svg></span><span>System Readiness</span></a>
                <a class="{{ request()->routeIs('admin.settings.*')?'active':'' }}" href="{{ route('admin.settings.edit') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2.2 2.2M16.8 16.8 19 19M19 5l-2.2 2.2M7.2 16.8 5 19"/></svg></span><span>Settings</span></a>
            @else
                <div class="nav-section">Workspace</div>
                <a class="{{ request()->routeIs('employee.home')?'active':'' }}" href="{{ route('employee.home') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7"/><path d="M5 9v12h14V9"/><path d="M9 21v-7h6v7"/></svg></span><span>Home</span></a>
                <a class="{{ request()->routeIs('employee.transactions.create')?'active':'' }}" href="{{ route('employee.transactions.create') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></span><span>New Transaction</span></a>
                <a class="{{ request()->routeIs('employee.transactions.index')||request()->routeIs('employee.transactions.show')?'active':'' }}" href="{{ route('employee.transactions.index') }}"><span class="nav-icon"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span><span>My History</span></a>
            @endif
        </nav>

        <div class="sidebar-foot">
            <div class="security-chip"><span>●</span><div><b>Secure session</b><small>Audit logging active</small></div></div>
            <a class="sidebar-profile" href="{{ route('profile.edit') }}">
                <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span>
                <span><b>{{ auth()->user()->name }}</b><small>{{ ucfirst(auth()->user()->role->value) }}</small></span>
            </a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="Open navigation">☰</button>
                <div>
                    <span class="topbar-kicker">{{ auth()->user()->isAdmin() ? 'Agent Audit Platform' : 'Agent Operations' }}</span>
                    <h1>@yield('topbar','Agent Audit')</h1>
                </div>
            </div>

            <div class="topbar-actions">
                <span class="role-pill">{{ ucfirst(auth()->user()->role->value) }}</span>
                <details class="profile-menu">
                    <summary>
                        <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span>
                        <span class="profile-summary-copy"><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->username }}</small></span>
                        <span class="chevron">⌄</span>
                    </summary>
                    <div class="profile-popover">
                        <a href="{{ route('profile.edit') }}">Profile & Security</a>
                        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
                    </div>
                </details>
            </div>
        </header>

        <div class="content">
            @if(session('success'))
                <div class="alert success flash-alert"><span>✓</span><div>{{ session('success') }}</div></div>
            @endif

            @if($errors->any())
                <div class="alert error flash-alert">
                    <span>!</span>
                    <div><b>Please check:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    @if(auth()->user()->isEmployee())
        <nav class="employee-bottom-nav">
            <a class="{{ request()->routeIs('employee.home')?'active':'' }}" href="{{ route('employee.home') }}"><span>⌂</span><small>Home</small></a>
            <a class="primary" href="{{ route('employee.transactions.create') }}"><span>＋</span><small>New</small></a>
            <a class="{{ request()->routeIs('employee.transactions.index')||request()->routeIs('employee.transactions.show')?'active':'' }}" href="{{ route('employee.transactions.index') }}"><span>≡</span><small>History</small></a>
        </nav>
    @endif
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const open = document.getElementById('navToggle');
    const close = document.getElementById('sidebarClose');

    function setOpen(value) {
        document.body.classList.toggle('sidebar-open', value);
        if (sidebar) sidebar.setAttribute('aria-hidden', value ? 'false' : 'true');
    }

    if (open) open.addEventListener('click', () => setOpen(true));
    if (close) close.addEventListener('click', () => setOpen(false));
    if (overlay) overlay.addEventListener('click', () => setOpen(false));

    document.querySelectorAll('.sidebar .nav a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 1100) setOpen(false);
        });
    });
})();
</script>
</body>
</html>
