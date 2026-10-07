@extends('layouts.app')
@section('title','Device Sessions')
@section('topbar','Device Sessions')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">SESSION SECURITY</span>
        <h2>Remembered Devices</h2>
        <p>Trusted devices stay signed in until logout, expiration, password rotation, user disablement or Admin revocation.</p>
    </div>
</div>

<section class="card">
    <div class="card-title-row"><div><span class="eyebrow">PERSISTENT LOGIN</span><h3>Device Sessions</h3></div><span class="count-pill">{{ $sessions->total() }}</span></div>
    <div class="session-list">
        @forelse($sessions as $session)
            <article class="session-card">
                <div class="session-icon">{{ match(true){str_contains(strtolower($session->device_label??''),'iphone')=>'◧',str_contains(strtolower($session->device_label??''),'android')=>'◩',default=>'▣'} }}</div>
                <div class="session-copy">
                    <b>{{ $session->user?->name ?? 'Unknown user' }} · {{ $session->device_label ?: 'Unknown device' }}</b>
                    <small>{{ str($session->user_agent)->limit(100) }}</small>
                    <div class="session-meta"><span class="mono">{{ $session->ip_address ?: 'No IP' }}</span><span>Last seen {{ $session->last_seen_at?->diffForHumans() ?? 'never' }}</span><span>Expires {{ $session->expires_at?->format('d M Y') }}</span></div>
                </div>
                <span class="status-pill {{ $session->revoked_at?'danger':'success' }}">{{ $session->revoked_at?'Revoked':'Active' }}</span>
                @if(!$session->revoked_at)
                    <form method="post" action="{{ route('admin.sessions.revoke',$session) }}">@csrf<button class="btn btn-sm btn-danger">Revoke</button></form>
                @endif
            </article>
        @empty
            <div class="empty">No remembered devices.</div>
        @endforelse
    </div>
    {{ $sessions->links() }}
</section>
@endsection
