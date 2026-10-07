<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#0a1020">
    <title>Sign in · Agent Audit</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="login">
    <div class="login-layout">
        <section class="login-brand-panel">
            <div class="login-brandmark"><span class="branddot">A</span><div><b>Agent Audit</b><small>Financial evidence control center</small></div></div>
            <div class="login-brand-copy">
                <span class="eyebrow">SECURE OPERATIONS</span>
                <h1>Evidence in.<br>Audit certainty out.</h1>
                <p>Agent top-ups, credit, commissions, bank evidence and reconciliation in one controlled workflow.</p>
            </div>
            <div class="login-security-row"><span>✓ Global duplicate protection</span><span>✓ Immutable audit trail</span><span>✓ Persistent trusted devices</span></div>
        </section>

        <section class="login-card">
            <div class="login-mobile-brand"><span class="branddot">A</span><b>Agent Audit</b></div>
            <span class="eyebrow">WELCOME BACK</span>
            <h1>Sign in</h1>
            <p class="small muted">Use your Agent Audit account. Trusted devices remain signed in until revoked or logged out.</p>

            @if($errors->any())
                <div class="alert error">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('login') }}" class="stack login-form">
                @csrf
                <div class="field"><label>Username</label><input class="input login-input" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus></div>
                <div class="field"><label>Password</label><input class="input login-input" type="password" name="password" autocomplete="current-password" required></div>
                <button class="btn btn-primary btn-lg" style="width:100%">Sign in securely →</button>
            </form>

            <div class="login-foot"><span>🔒</span><span>Credentials are protected by Laravel authentication and device-session controls.</span></div>
        </section>
    </div>
</body>
</html>
