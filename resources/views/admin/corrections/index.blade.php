@extends('layouts.app')
@section('title','Correction Approvals')
@section('topbar','Correction Approvals')
@section('content')

<div class="page-head">
    <div>
        <span class="eyebrow">HUMAN REVIEW</span>
        <h2>Correction Approval Queue</h2>
        <p>Employees never overwrite AI values. Review the source screenshot beside the requested correction before deciding.</p>
    </div>
    <form method="get">
        <select class="select" name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ str($status->value)->replace('_',' ')->title() }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="stack">
@forelse($requests as $requestItem)
    <section class="card review-card">
        <div class="review-header">
            <div>
                <div class="eyebrow">{{ str($requestItem->field_name)->replace('_',' ')->upper() }}</div>
                <h3 style="margin:4px 0">
                    <a href="{{ route('admin.transactions.show',$requestItem->transaction) }}">{{ $requestItem->transaction->reference }}</a>
                </h3>
                <div class="small muted">
                    {{ $requestItem->transaction->agent?->agent_id ?? 'Agent pending' }}
                    · {{ $requestItem->transaction->brand?->name ?? 'Brand pending' }}
                    · Requested by {{ $requestItem->requester?->name }}
                    · {{ $requestItem->created_at->format('d M Y H:i') }}
                </div>
            </div>
            <span class="status-pill {{ match($requestItem->status->value){'approved'=>'success','rejected'=>'danger','needs_new_screenshot'=>'warning',default=>'warning'} }}">
                {{ str($requestItem->status->value)->replace('_',' ')->title() }}
            </span>
        </div>

        <div class="evidence-review-grid">
            <div class="evidence-preview-panel">
                <div class="panel-label">ORIGINAL SCREENSHOT</div>
                @if($requestItem->evidenceFile)
                    <a href="{{ route('evidence.show',$requestItem->evidenceFile) }}" target="_blank" class="evidence-image-link">
                        <img src="{{ route('evidence.show',$requestItem->evidenceFile) }}" alt="Source evidence">
                    </a>
                    <div class="tiny muted" style="margin-top:8px">
                        {{ str($requestItem->evidenceFile->kind->value)->replace('_',' ')->title() }}
                        · Confidence {{ $requestItem->evidenceFile->critical_confidence ? round($requestItem->evidenceFile->critical_confidence*100,1).'%' : '—' }}
                    </div>
                @else
                    <div class="empty evidence-placeholder">No evidence image attached to this correction.</div>
                @endif
            </div>

            <div class="review-values-panel">
                <div class="compare-grid">
                    <div class="compare-value original">
                        <span>AI ORIGINAL</span>
                        <strong>{{ is_array($requestItem->ai_value) ? json_encode($requestItem->ai_value,JSON_UNESCAPED_UNICODE) : ($requestItem->ai_value ?? '—') }}</strong>
                    </div>
                    <div class="compare-arrow">→</div>
                    <div class="compare-value proposed">
                        <span>EMPLOYEE PROPOSED</span>
                        <strong>{{ is_array($requestItem->proposed_value) ? json_encode($requestItem->proposed_value,JSON_UNESCAPED_UNICODE) : ($requestItem->proposed_value ?? '—') }}</strong>
                    </div>
                </div>

                <div class="review-reason">
                    <span class="panel-label">EMPLOYEE REASON</span>
                    <p>{{ $requestItem->reason }}</p>
                </div>

                @if($requestItem->status->value==='pending')
                    <div class="review-actions">
                        <form method="post" action="{{ route('admin.corrections.approve',$requestItem) }}" class="stack">
                            @csrf
                            <input class="input" name="note" placeholder="Optional approval note">
                            <button class="btn btn-success">Approve Correction</button>
                        </form>

                        <form method="post" action="{{ route('admin.corrections.reject',$requestItem) }}" class="stack">
                            @csrf
                            <input class="input" name="note" required placeholder="Reason for rejection">
                            <label class="check"><input type="checkbox" name="needs_screenshot" value="1"> Require a new clearer screenshot</label>
                            <button class="btn btn-danger">Reject</button>
                        </form>
                    </div>
                @elseif($requestItem->admin_note)
                    <div class="alert info"><b>Admin note:</b> {{ $requestItem->admin_note }}</div>
                @endif
            </div>
        </div>
    </section>
@empty
    <div class="card empty">No correction requests match this filter.</div>
@endforelse
</div>

{{ $requests->links() }}
@endsection
