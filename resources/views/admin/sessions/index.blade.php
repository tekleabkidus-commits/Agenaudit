@extends('layouts.app')
@section('title','Device Sessions')
@section('topbar','Device Sessions')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">SESSION SECURITY</span>
        <h2>Remembered Devices</h2>
        <p>Trusted devices stay signed in with a sliding persistent session until logout, password rotation, account disablement, expiration or Admin revocation.</p>
    </div>
</div>

@if($usersWithSessions->isNotEmpty())
<section class="card" style="margin-bottom:16px">
    <div class="card-title-row">
        <div><span class="eyebrow">USER-LEVEL CONTROL</span><h3>Revoke All Devices</h3></div>
        <span class="count-pill">{{ $usersWithSessions->count() }}</span>
    </div>

    <div class="revoke-user-grid">
        @foreach($usersWithSessions as $user)
            <article class="revoke-user-card">
                <div class="entity-cell">
                    <div class="entity-avatar">{{ strtoupper(substr($user->name,0,1)) }}</div>
                    <div>
                        <b>{{ $user->name }}</b>
                        <div class="tiny muted">{{ $user->username }} · {{ $user->active_device_sessions_count }} active device(s)</div>
                    </div>
                </div>
                <form method="post" action="{{ route('admin.sessions.revoke-all',$user) }}">
                    @csrf
                    <button class="btn btn-sm btn-danger" onclick="return confirm('Revoke every remembered device for {{ addslashes($user->name) }}?')">Revoke All</button>
                </form>
            </article>
        @endforeach
    </div>
</section>
@endif

<section class="card">
    <div class="card-title-row">
        <div><span class="eyebrow">PERSISTENT LOGIN</span><h3>Individual Device Sessions</h3></div>
        <span class="count-pill">{{ $sessions->total() }}</span>
    </div>

    <div class="session-list">
        @forelse($sessions as $session)
            <article class="session-card">
                <div class="session-icon">{{ match(true){str_contains(strtolower($session->device_label??''),'iphone')=>'◧',str_contains(strtolower($session->device_label??''),'android')=>'◩',default=>'▣'} }}</div>
                <div class="session-copy">
                    <b>{{ $session->user?->name ?? 'Unknown user' }} · {{ $session->device_label ?: 'Unknown device' }}</b>
                    <small>{{ str($session->user_agent)->limit(100) }}</small>
                    <div class="session-meta">
                        <span class="mono">{{ $session->ip_address ?: 'No IP' }}</span>
                        <span>Last seen {{ $session->last_seen_at?->diffForHumans() ?? 'never' }}</span>
                        <span>Expires {{ $session->expires_at?->format('d M Y') }}</span>
                    </div>
                </div>
                <span class="status-pill {{ $session->revoked_at?'danger':'success' }}">{{ $session->revoked_at?'Revoked':'Active' }}</span>
                @if(!$session->revoked_at)
                    <form method="post" action="{{ route('admin.sessions.revoke',$session) }}">
                        @csrf
                        <button class="btn btn-sm btn-danger">Revoke</button>
                    </form>
                @endif
            </article>
        @empty
            <div class="empty">No remembered devices.</div>
        @endforelse
    </div>

    {{ $sessions->links() }}
</section>
@endsection
