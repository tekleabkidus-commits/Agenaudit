@extends('layouts.app')
@section('title','Transaction '.$transaction->reference)
@section('topbar','Transaction Detail')
@section('content')

@php
    $finalized = in_array($transaction->status->value,['completed','rejected','cancelled'],true);
@endphp

<div class="page-head">
    <div>
        <span class="eyebrow">{{ $transaction->type->label() }}</span>
        <h2>{{ $transaction->reference }}</h2>
        <p>{{ $transaction->created_at->format('d M Y, H:i') }} · {{ $transaction->employee?->name }}</p>
    </div>
    <span class="status-pill {{ match($transaction->status->value){'completed'=>'success','rejected'=>'danger','cancelled'=>'neutral','ready_for_review'=>'success','pending_employee_confirmation'=>'info',default=>'warning'} }}">
        {{ str($transaction->status->value)->replace('_',' ')->title() }}
    </span>
</div>

@if($transaction->review_reason)
    <div class="alert warning"><b>Review:</b> {{ $transaction->review_reason }}</div>
@endif
@if($transaction->rejection_reason)
    <div class="alert error"><b>Rejected:</b> {{ $transaction->rejection_reason }}</div>
@endif

<div class="transaction-overview-grid">
    <section class="card identity-panel">
        <span class="eyebrow">TRANSACTION IDENTITY</span>
        <div class="identity-agent">
            <div class="entity-avatar large">{{ strtoupper(substr($transaction->agent?->username ?? '?',0,1)) }}</div>
            <div>
                <h3>{{ $transaction->agent?->agent_id ?? 'Agent not identified' }}</h3>
                <p>{{ $transaction->agent?->username }} @if($transaction->brand) · {{ $transaction->brand->name }} @endif</p>
            </div>
        </div>

        <div class="detail-grid">
            <div><span>Agent-system amount</span><b>{{ $transaction->amount!==null?number_format((float)$transaction->amount,2).' ETB':'—' }}</b></div>
            <div><span>Valid payment total</span><b>{{ number_format((float)$transaction->valid_payment_total,2) }} ETB</b></div>
            <div><span>Balance before</span><b>{{ $transaction->agent_balance_before!==null?number_format((float)$transaction->agent_balance_before,2).' ETB':'—' }}</b></div>
            <div><span>Balance after</span><b>{{ $transaction->agent_balance_after!==null?number_format((float)$transaction->agent_balance_after,2).' ETB':'—' }}</b></div>
            <div><span>Agent-system reference</span><b class="mono">{{ $transaction->agent_system_reference ?: '—' }}</b></div>
            <div><span>Agent-system time</span><b>{{ $transaction->agent_system_at?->format('d M Y H:i:s') ?? '—' }}</b></div>
            <div><span>Current credit</span><b>{{ $currentOutstanding!==null?number_format($currentOutstanding,2).' ETB':'—' }}</b></div>
            <div><span>Risk</span><b>{{ str($transaction->risk_level?->value ?? '—')->title() }}</b></div>
            <div><span>Check.et</span><b>{{ str($transaction->external_verification_status?->value ?? '—')->replace('_',' ')->title() }}</b></div>
            <div><span>Completed</span><b>{{ $transaction->completed_at?->format('d M Y H:i:s') ?? '—' }}</b></div>
        </div>
    </section>

    @if($transaction->issuedCreditRecord)
        <section class="card credit-detail-panel">
            <span class="eyebrow">CREDIT RECORD</span>
            <h3>{{ str($transaction->issuedCreditRecord->status)->title() }}</h3>
            <div class="detail-grid">
                <div><span>Original</span><b>{{ number_format((float)$transaction->issuedCreditRecord->original_amount,2) }} ETB</b></div>
                <div><span>Repaid</span><b>{{ number_format((float)$transaction->issuedCreditRecord->repaid_amount,2) }} ETB</b></div>
                <div><span>Outstanding</span><b>{{ number_format((float)$transaction->issuedCreditRecord->outstanding_amount,2) }} ETB</b></div>
                <div><span>Due</span><b>{{ $transaction->issuedCreditRecord->due_at?->format('d M Y') ?? 'No due date' }}</b></div>
            </div>
            @if($transaction->issuedCreditRecord->repaymentAllocations->count())
                <div class="subsection">
                    <span class="panel-label">REPAYMENTS</span>
                    @foreach($transaction->issuedCreditRecord->repaymentAllocations as $allocation)
                        <div class="status-line">
                            <span>{{ $allocation->allocated_at?->format('d M Y H:i') }}</span>
                            <span><b>{{ number_format((float)$allocation->amount,2) }} ETB</b> · <a href="{{ route('admin.transactions.show',$allocation->repaymentTransaction) }}">open</a></span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>

<section class="card" style="margin-top:16px">
    <div class="card-title-row">
        <div><span class="eyebrow">PAYMENT EVIDENCE</span><h3>Bank Payments</h3></div>
        <span class="count-pill">{{ $transaction->payments->count() }}</span>
    </div>

    <div class="payment-admin-grid">
    @forelse($transaction->payments as $payment)
        <article class="payment-card {{ $payment->internal_status->value==='rejected'?'rejected':'' }}">
            <div class="payment-head">
                <div>
                    <b>{{ $payment->fromBank?->name ?? $payment->from_bank_raw ?? '?' }} <span class="arrow">→</span> {{ $payment->toBank?->name ?? $payment->to_bank_raw ?? '?' }}</b>
                    <div class="mono tiny muted">{{ $payment->transaction_id_raw }}</div>
                </div>
                <span class="status-pill {{ $payment->internal_status->value==='valid'?'success':($payment->internal_status->value==='rejected'?'danger':'warning') }}">{{ str($payment->internal_status->value)->title() }}</span>
            </div>

            <div class="detail-grid compact-details">
                <div><span>Reconciled transfer amount</span><b>{{ number_format((float)$payment->amount,2) }} ETB</b><small>Counted only when internally valid</small></div>
                <div><span>Check.et reported amount</span><b>{{ is_numeric(data_get($payment->external_response,'data.receipt.amount')) ? number_format((float)data_get($payment->external_response,'data.receipt.amount'),2).' ETB' : 'Not supplied' }}</b>
                    <small>Reported by provider; not necessarily fee-exclusive</small></div>
                <div><span>Check.et amount meaning</span>
                    <b>{{ str($payment->fromBank?->check_et_amount_meaning ?? 'unknown')->replace('_',' ')->title() }}</b>
                    <small>Configured separately for each transaction issuer</small></div>
                @if(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.total_debited') !== null)
                    <div><span>Screenshot payer debit</span>
                        <b>{{ number_format((float)data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.total_debited'),2) }} ETB</b></div>
                @endif
                @if(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.total_fees') !== null)
                    <div><span>Visible fees / taxes</span>
                        <b>{{ number_format((float)data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.total_fees'),2) }} ETB</b>
                        <small>{{ data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.fees_known_complete') ? 'Complete receipt fee breakdown' : 'May omit additional charges' }}</small></div>
                @endif
                @if(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.family'))
                    <div><span>Receipt identity</span><b>{{ str(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.family'))->replace('_',' ')->title() }}</b>
                        <small>Based on screenshot patterns; not proof of payment settlement</small></div>
                    <div><span>Sender identification</span><b>{{ str(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.source_method','unconfirmed'))->replace('_',' ')->title() }}</b></div>
                    <div><span>Amount basis</span><b>{{ str(data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.amount_source','screenshot_amount'))->replace('_',' ')->title() }}</b></div>
                @endif
                <div><span>Date/time</span><b>{{ $payment->transaction_at?->format('d M Y H:i:s') ?? '—' }}</b></div>
                <div><span>Sender</span><b>{{ $payment->sender_name ?: '—' }}</b><small>{{ $payment->sender_account ?: '—' }}</small></div>
                <div><span>Receiver</span><b>{{ $payment->receiver_name ?: '—' }}</b><small>{{ $payment->receiver_account ?: '—' }}</small></div>
                <div><span>Account match</span><b>{{ $payment->account_match_method ?? '—' }}</b><small>{{ $payment->account_match_confidence ? round($payment->account_match_confidence*100,1).'%' : '' }}</small></div>
                <div><span>Time difference</span><b>{{ $payment->time_difference_minutes!==null?$payment->time_difference_minutes.' min':'—' }}</b></div>
                <div><span>External verification</span><b>{{ str($payment->external_status->value)->replace('_',' ')->title() }}</b></div>
                <div><span>Risk</span><b>{{ str($payment->risk_level?->value ?? '—')->title() }}</b></div>
            </div>

            @if($payment->rejection_reason)
                <div class="alert error" style="margin-top:10px"><b>{{ str($payment->rejection_code)->replace('_',' ')->title() }}:</b> {{ $payment->rejection_reason }}</div>
            @endif

            @if($payment->duplicateOf)
                <div class="duplicate-link-box">
                    <span>Original duplicate match</span>
                    <div>
                        <b>{{ $payment->duplicateOf->transaction_id_raw }}</b>
                        @if($payment->duplicateOf->transaction)
                            · <a href="{{ route('admin.transactions.show',$payment->duplicateOf->transaction) }}">Open original transaction</a>
                        @endif
                    </div>
                </div>
            @endif

            @if($payment->evidenceFile)
                <a class="btn btn-sm btn-outline" style="margin-top:10px" href="{{ route('evidence.show',$payment->evidenceFile) }}" target="_blank">Open Screenshot</a>
            @endif
        </article>
    @empty
        <div class="empty">No bank payment records yet.</div>
    @endforelse
    </div>
</section>

<section class="card" style="margin-top:16px">
    <div class="card-title-row">
        <div><span class="eyebrow">SOURCE FILES</span><h3>Evidence</h3></div>
        <span class="count-pill">{{ $transaction->evidenceFiles->count() }}</span>
    </div>

    <div class="evidence-gallery">
    @foreach($transaction->evidenceFiles as $evidence)
        <article class="evidence-tile">
            <a href="{{ route('evidence.show',$evidence) }}" target="_blank" class="evidence-thumb">
                <img src="{{ route('evidence.show',$evidence) }}" alt="Evidence">
            </a>
            <div class="evidence-meta">
                <b>{{ str($evidence->kind->value)->replace('_',' ')->title() }}</b>
                <span class="status-pill {{ $evidence->status->value==='extracted'?'success':($evidence->status->value==='failed'?'danger':'warning') }}">{{ str($evidence->status->value)->replace('_',' ')->title() }}</span>
                <small>Quality {{ $evidence->quality_score?round($evidence->quality_score*100).'%':'—' }} · Confidence {{ $evidence->critical_confidence?round($evidence->critical_confidence*100).'%':'—' }}</small>
                @if($evidence->employee_confirmed_at)<small>Employee confirmed {{ $evidence->employee_confirmed_at->format('d M H:i') }}</small>@endif
            </div>

            @if($evidence->status===App\Enums\EvidenceStatus::PendingAdminExtraction)
                <details class="manual-extraction">
                    <summary class="btn btn-sm btn-primary">Manual Extraction</summary>
                    <form method="post" action="{{ route('admin.evidence.manual-extraction',$evidence) }}" class="stack" style="margin-top:10px">
                        @csrf
                        @if($evidence->kind===App\Enums\EvidenceKind::AgentSystem)
                            <input class="input" name="agent_id" placeholder="Agent ID" required>
                            <input class="input" name="agent_username" placeholder="Agent username" required>
                            <input class="input" name="amount" type="number" step=".01" placeholder="Amount" required>
                            <input class="input" name="transaction_at" type="datetime-local" required>
                            <input class="input" name="brand_hint" placeholder="Brand hint (optional)">
                        @else
                            <input class="input" name="from_bank" placeholder="From bank" required>
                            <input class="input" name="to_bank" placeholder="To bank" required>
                            <input class="input" name="sender_account" placeholder="Sender account/phone">
                            <input class="input" name="sender_name" placeholder="Sender name">
                            <input class="input" name="receiver_account" placeholder="Receiver account (masked accepted)" required>
                            <input class="input" name="receiver_name" placeholder="Receiver full name" required>
                            <input class="input" name="amount" type="number" step=".01" placeholder="Amount" required>
                            <input class="input" name="transaction_id" placeholder="Bank transaction ID" required>
                            <input class="input" name="transaction_at" type="datetime-local" required>
                        @endif
                        <button class="btn btn-primary">Apply Through Validation Engine</button>
                    </form>
                </details>
            @endif
        </article>
    @endforeach
    </div>
</section>

<div class="grid grid-2" style="margin-top:16px">
    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">CHANGE CONTROL</span><h3>Correction History</h3></div></div>
        @forelse($transaction->correctionRequests as $correction)
            <div class="correction-line">
                <div>
                    <b>{{ str($correction->field_name)->replace('_',' ')->title() }}</b>
                    <small>AI: {{ is_array($correction->ai_value)?json_encode($correction->ai_value):$correction->ai_value }} → Proposed: {{ is_array($correction->proposed_value)?json_encode($correction->proposed_value):$correction->proposed_value }}</small>
                </div>
                <span class="status-pill {{ $correction->status->value==='approved'?'success':($correction->status->value==='rejected'?'danger':'warning') }}">{{ str($correction->status->value)->replace('_',' ')->title() }}</span>
            </div>
        @empty
            <div class="empty">No correction requests.</div>
        @endforelse
    </section>

    <section class="card">
        <div class="card-title-row"><div><span class="eyebrow">AUDIT TRAIL</span><h3>Activity Timeline</h3></div></div>
        <div class="timeline">
            @forelse($transaction->events->sortByDesc('created_at') as $event)
                <div class="event">
                    <b>{{ str($event->event_type)->replace('_',' ')->title() }}</b>
                    <div class="small">{{ $event->message }}</div>
                    <div class="time">{{ $event->created_at->format('d M Y H:i:s') }} · {{ $event->actor?->name ?? 'System' }}</div>
                </div>
            @empty
                <div class="empty">No events recorded.</div>
            @endforelse
        </div>
    </section>
</div>

@if(!$finalized)
<section class="card admin-action-bar" style="margin-top:16px">
    <div>
        <span class="eyebrow">ADMIN ACTIONS</span>
        <h3>Review Controls</h3>
        <p>Internal hard rules remain authoritative. External override affects only secondary verification.</p>
    </div>

    <div class="admin-action-grid">
        <form method="post" action="{{ route('admin.transactions.revalidate',$transaction) }}">@csrf<button class="btn btn-outline">Revalidate Internal Rules</button></form>
        <form method="post" action="{{ route('admin.transactions.retry-external',$transaction) }}">@csrf<button class="btn btn-outline">Retry Check.et</button></form>

        <form method="post" action="{{ route('admin.transactions.external-override',$transaction) }}" class="stack action-form">
            @csrf
            <textarea class="textarea" name="note" placeholder="Reason for external-verification override" required></textarea>
            <button class="btn btn-primary">Approve Secondary-Verification Override</button>
        </form>

        <form method="post" action="{{ route('admin.transactions.reject',$transaction) }}" class="stack action-form">
            @csrf
            <textarea class="textarea" name="reason" placeholder="Rejection reason" required></textarea>
            <button class="btn btn-danger">Reject Transaction</button>
        </form>
    </div>
</section>
@endif
@endsection
