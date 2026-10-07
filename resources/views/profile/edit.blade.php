@extends('layouts.app')
@section('title','Profile')
@section('topbar','Profile & Security')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">ACCOUNT SECURITY</span>
        <h2>{{ $user->name }}</h2>
        <p>{{ $user->username }} · {{ ucfirst($user->role->value) }}</p>
    </div>
</div>

<div class="grid grid-2">
    <section class="card elevated">
        <div class="card-title-row"><div><span class="eyebrow">CREDENTIALS</span><h3>Change Password</h3></div><span class="icon-bubble">⌁</span></div>
        <p class="small muted">Changing your password revokes other remembered devices. This device receives a fresh secure persistent token.</p>
        <form method="post" action="{{ route('profile.password') }}" class="stack">
            @csrf @method('PUT')
            <div class="field"><label>Current password</label><input class="input" type="password" name="current_password" autocomplete="current-password" required></div>
            <div class="field"><label>New password</label><input class="input" type="password" name="password" autocomplete="new-password" minlength="12" required></div>
            <div class="field"><label>Confirm new password</label><input class="input" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></div>
            <button class="btn btn-primary btn-lg">Update Password</button>
        </form>
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">TRUSTED DEVICES</span><h3>Your Remembered Sessions</h3></div><span class="count-pill">{{ $devices->count() }}</span></div>
        <div class="session-list compact-sessions">
            @forelse($devices as $device)
                <article class="session-card">
                    <div class="session-icon">▣</div>
                    <div class="session-copy">
                        <b>{{ $device->device_label ?: 'Unknown device' }}</b>
                        <small class="mono">{{ $device->ip_address ?: 'No IP' }}</small>
                        <div class="session-meta"><span>{{ optional($device->last_seen_at)->format('d M Y H:i') ?? 'Never seen' }}</span></div>
                    </div>
                    <span class="status-pill {{ $device->isUsable()?'success':'danger' }}">{{ $device->isUsable()?'Active':'Revoked / expired' }}</span>
                </article>
            @empty
                <div class="empty">No remembered devices.</div>
            @endforelse
        </div>
        @if($user->isAdmin())<div class="tiny muted" style="margin-top:12px">Individual sessions can be revoked from Admin → Device Sessions.</div>@endif
    </section>
</div>
@endsection
