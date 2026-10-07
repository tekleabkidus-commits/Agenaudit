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
                <a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}"><span class="nav-icon">⌂</span><span>Dashboard</span></a>
                <a class="{{ request()->routeIs('admin.transactions.*')?'active':'' }}" href="{{ route('admin.transactions.index') }}"><span class="nav-icon">⇄</span><span>Transactions</span></a>
                <a class="{{ request()->routeIs('admin.corrections.*')?'active':'' }}" href="{{ route('admin.corrections.index') }}"><span class="nav-icon">✓</span><span>Approvals</span></a>
                <a class="{{ request()->routeIs('admin.credits.*')?'active':'' }}" href="{{ route('admin.credits.index') }}"><span class="nav-icon">◉</span><span>Credit</span></a>
                <a class="{{ request()->routeIs('admin.commissions.*')?'active':'' }}" href="{{ route('admin.commissions.index') }}"><span class="nav-icon">◇</span><span>Commission</span></a>
                <a class="{{ request()->routeIs('admin.reports.*')?'active':'' }}" href="{{ route('admin.reports.index') }}"><span class="nav-icon">▥</span><span>Reports</span></a>

                <div class="nav-section">Master Data</div>
                <a class="{{ request()->routeIs('admin.brands.*')?'active':'' }}" href="{{ route('admin.brands.index') }}"><span class="nav-icon">◈</span><span>Brands</span></a>
                <a class="{{ request()->routeIs('admin.agents.*')?'active':'' }}" href="{{ route('admin.agents.index') }}"><span class="nav-icon">◎</span><span>Agents</span></a>
                <a class="{{ request()->routeIs('admin.agent-imports.*')?'active':'' }}" href="{{ route('admin.agent-imports.index') }}"><span class="nav-icon">⇧</span><span>Agent Import</span></a>
                <a class="{{ request()->routeIs('admin.banks.*')?'active':'' }}" href="{{ route('admin.banks.index') }}"><span class="nav-icon">▣</span><span>Banks</span></a>
                <a class="{{ request()->routeIs('admin.receiving-accounts.*')?'active':'' }}" href="{{ route('admin.receiving-accounts.index') }}"><span class="nav-icon">▤</span><span>Receiving Accounts</span></a>

                <div class="nav-section">Administration</div>
                <a class="{{ request()->routeIs('admin.employees.*')?'active':'' }}" href="{{ route('admin.employees.index') }}"><span class="nav-icon">♙</span><span>Users & Permissions</span></a>
                <a class="{{ request()->routeIs('admin.sessions.*')?'active':'' }}" href="{{ route('admin.sessions.index') }}"><span class="nav-icon">◌</span><span>Device Sessions</span></a>
                <a class="{{ request()->routeIs('admin.audit.*')?'active':'' }}" href="{{ route('admin.audit.index') }}"><span class="nav-icon">≡</span><span>Audit Log</span></a>
                <a class="{{ request()->routeIs('admin.settings.*')?'active':'' }}" href="{{ route('admin.settings.edit') }}"><span class="nav-icon">⚙</span><span>Settings</span></a>
            @else
                <div class="nav-section">Workspace</div>
                <a class="{{ request()->routeIs('employee.home')?'active':'' }}" href="{{ route('employee.home') }}"><span class="nav-icon">⌂</span><span>Home</span></a>
                <a class="{{ request()->routeIs('employee.transactions.create')?'active':'' }}" href="{{ route('employee.transactions.create') }}"><span class="nav-icon">＋</span><span>New Transaction</span></a>
                <a class="{{ request()->routeIs('employee.transactions.index')||request()->routeIs('employee.transactions.show')?'active':'' }}" href="{{ route('employee.transactions.index') }}"><span class="nav-icon">≡</span><span>My History</span></a>
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
