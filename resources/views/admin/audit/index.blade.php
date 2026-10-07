@extends('layouts.app')
@section('title','Audit Log')
@section('topbar','Audit Log')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">IMMUTABLE HISTORY</span>
        <h2>Audit Log</h2>
        <p>Who changed what, when it happened, and the session context behind the action.</p>
    </div>
</div>

<div class="card filter-card">
    <form class="filters" method="get">
        <input class="input" name="action" value="{{ request('action') }}" placeholder="Search action name">
        <select class="select" name="actor">
            <option value="">Any actor</option>
            @foreach($users as $user)<option value="{{ $user->id }}" @selected(request('actor')==$user->id)>{{ $user->name }}</option>@endforeach
        </select>
        <button class="btn btn-primary">Filter</button>
        @if(request()->hasAny(['action','actor']))<a class="btn btn-ghost" href="{{ route('admin.audit.index') }}">Reset</a>@endif
    </form>
</div>

<section class="card" style="margin-top:16px">
    <div class="card-title-row"><div><span class="eyebrow">EVENT STREAM</span><h3>Recorded Actions</h3></div><span class="count-pill">{{ $logs->total() }}</span></div>
    <div class="audit-list">
        @forelse($logs as $log)
            <details class="audit-entry">
                <summary>
                    <span class="audit-dot"></span>
                    <span class="audit-time">{{ $log->created_at->format('d M Y') }}<small>{{ $log->created_at->format('H:i:s') }}</small></span>
                    <span class="audit-copy">
                        <b>{{ str($log->action)->replace(['.','_'],' ')->title() }}</b>
                        <small>{{ $log->actor?->name ?? 'System' }} · {{ class_basename($log->auditable_type??'System') }} @if($log->auditable_id)#{{ $log->auditable_id }}@endif</small>
                    </span>
                    <span class="mono tiny muted">{{ $log->ip_address }}</span>
                    <span class="details-chevron">⌄</span>
                </summary>
                <div class="audit-detail">
                    <div class="detail-grid">
                        <div><span>Actor</span><b>{{ $log->actor?->name ?? 'System' }}</b></div>
                        <div><span>IP address</span><b class="mono">{{ $log->ip_address ?: '—' }}</b></div>
                        <div><span>Subject type</span><b>{{ class_basename($log->auditable_type??'—') }}</b></div>
                        <div><span>Subject ID</span><b>{{ $log->auditable_id ?? '—' }}</b></div>
                    </div>
                    <pre class="audit-json">{{ json_encode(['before'=>$log->before,'after'=>$log->after,'metadata'=>$log->metadata],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </details>
        @empty
            <div class="empty">No audit records match this filter.</div>
        @endforelse
    </div>
    {{ $logs->links() }}
</section>
@endsection
