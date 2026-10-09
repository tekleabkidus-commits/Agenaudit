@extends('layouts.app')
@section('title','Transaction')
@section('topbar','Transaction')
@section('content')

@php
    $agentProofs = $transaction->evidenceFiles
        ->where('kind',App\Enums\EvidenceKind::AgentSystem)
        ->whereNull('superseded_by_id')
        ->sortBy('id')
        ->values();
    $agentEvidence = $agentProofs->first();

    $pendingBankEvidence = $transaction->evidenceFiles
        ->where('kind',App\Enums\EvidenceKind::BankPayment)
        ->where('status',App\Enums\EvidenceStatus::PendingEmployeeConfirmation);

    $finalized = in_array($transaction->status->value,['completed','rejected','cancelled'],true);
@endphp

<div class="mobile-workspace">
    <div class="transaction-hero">
        <div>
            <div class="eyebrow">{{ $transaction->type->label() }}</div>
            <h2>{{ $transaction->agent?->agent_id ?? 'New transaction' }}</h2>
            <div class="mono tiny muted">{{ $transaction->reference }}</div>
        </div>
        <span class="status-pill {{ match($transaction->status->value){'completed'=>'success','rejected'=>'danger','cancelled'=>'neutral','ready_for_review'=>'success','pending_employee_confirmation'=>'info',default=>'warning'} }}">
            {{ str($transaction->status->value)->replace('_',' ')->title() }}
        </span>
    </div>

    @if($transaction->review_reason)
        <div class="alert warning">{{ $transaction->review_reason }}</div>
    @endif
    @if($transaction->rejection_reason)
        <div class="alert error"><b>Rejected:</b> {{ $transaction->rejection_reason }}</div>
    @endif

    @if($transaction->agent)
        <section class="card agent-identity-card">
            <div class="entity-cell">
                <div class="entity-avatar large">{{ strtoupper(substr($transaction->agent->username,0,1)) }}</div>
                <div>
                    <span class="eyebrow">IDENTIFIED AUTOMATICALLY</span>
                    <h3>{{ $transaction->agent->agent_id }} · {{ $transaction->agent->username }}</h3>
                    <div class="small muted">{{ $transaction->brand->name }} · Brand comes from master agent data</div>
                </div>
            </div>
            @if(($currentOutstanding??0)>0)
                <span class="status-pill warning">IN CREDIT {{ number_format($currentOutstanding,2) }} ETB</span>
            @endif
        </section>
    @endif

    @if($transaction->type->requiresBankEvidence() && $transaction->agent_id)
        @php
            $paymentRows = $transaction->payments;
            $confirmedRows = $paymentRows->filter(fn($p) => $p->internal_status === App\Enums\PaymentValidationStatus::Valid);
            $unresolvedRows = $paymentRows->filter(fn($p) => $p->internal_status === App\Enums\PaymentValidationStatus::Review);
            $unverifiedRows = $confirmedRows->filter(fn($p) => $p->external_status !== App\Enums\ExternalVerificationStatus::Passed);
            $confirmedSum = (float) $confirmedRows->sum('amount');
            $checkEtConfirmedSum = (float) $confirmedRows->filter(fn($p) =>
                $p->external_status === App\Enums\ExternalVerificationStatus::Passed
            )->sum('amount');
            $targetTopup = $transaction->type === App\Enums\TransactionType::CreditRepayment ? null : (float) $transaction->amount;
            $eligibleRows = $paymentRows->filter(fn($p) =>
                $p->internal_status === App\Enums\PaymentValidationStatus::Valid
                || ($p->internal_status === App\Enums\PaymentValidationStatus::Review
                    && in_array($p->rejection_code, ['transfer_amount_unconfirmed','verification_amount_semantics_unknown'], true))
            );
        @endphp
        <section class="card validation-card" style="margin-top:14px">
            <div class="card-title-row">
                <div><span class="eyebrow">RECEIPT RECONCILIATION</span><h3>One agent top-up · multiple bank transfers</h3></div>
                <span class="count-pill">{{ $paymentRows->count() }} receipt(s)</span>
            </div>
            <div class="validation-list">
                <div><span>Agent-system top-up target</span><b>{{ $targetTopup !== null ? number_format($targetTopup,2).' ETB' : 'Outstanding credit repayment' }}</b></div>
                <div><span>Amounts supported by readable receipt evidence</span><b>{{ number_format($confirmedSum,2) }} ETB</b></div>
                <div><span>Check.et-confirmed receipt amounts</span><b>{{ number_format($checkEtConfirmedSum,2) }} ETB</b></div>
                @if($targetTopup !== null)
                    <div><span>Difference from target (not a bank verification)</span><b>{{ number_format($confirmedSum - $targetTopup,2) }} ETB</b></div>
                @endif
                <div><span>Receipts needing amount review</span><b>{{ $unresolvedRows->count() }}</b></div>
                <div><span>Readable receipts without successful Check.et verification</span><b>{{ $unverifiedRows->count() }}</b></div>
            </div>
            <p class="small muted" style="margin-top:10px">An Employee may credit the agent once using several separate payments. Matching the agent amount helps reconcile the receipts, but does not prove that the bank received the money. Unknown fees are not subtracted by guessing.</p>

            @if(!$finalized && $eligibleRows->isNotEmpty())
                <form method="post" action="{{ route('employee.transactions.recheck-check-et',$transaction) }}" style="margin-top:12px">
                    @csrf
                    <button class="btn btn-primary" type="submit">↻ Recheck all {{ $eligibleRows->count() }} receipt(s) with Check.et</button>
                </form>
                <p class="tiny muted" style="margin-top:8px">Runs in the background using saved references. Refresh this page after the queue worker finishes; no reupload required. A failed check keeps the transaction pending.</p>
            @endif
        </section>
    @endif

    @if($transaction->type === App\Enums\TransactionType::Commission && $transaction->agent)
        <section class="card commission-eligibility {{ $transaction->agent->commission_enabled && $transaction->agent->brand?->commission_enabled ? 'enabled' : 'disabled' }}">
            <div class="card-title-row">
                <div>
                    <span class="eyebrow">COMMISSION ELIGIBILITY</span>
                    <h3>{{ $transaction->agent->commission_enabled && $transaction->agent->brand?->commission_enabled ? 'Commission Deposit is enabled' : 'Commission Deposit is disabled by brand or agent' }}</h3>
                </div>
                <span class="status-pill {{ $transaction->agent->commission_enabled && $transaction->agent->brand?->commission_enabled?'success':'danger' }}">{{ $transaction->agent->commission_enabled && $transaction->agent->brand?->commission_enabled?'ON':'OFF' }}</span>
            </div>
            <div class="mini-stat-row">
                <div><span>Used this month</span><b>{{ $commissionUsedThisMonth ?? 0 }}</b></div>
                <div><span>Monthly limit</span><b>{{ $transaction->agent->commission_monthly_limit }}</b></div>
                <div><span>Remaining</span><b>{{ $commissionRemainingThisMonth ?? 0 }}</b></div>
            </div>
            <div class="tiny muted">Only Admin can enable Commission Deposit at both brand and agent level.</div>
        </section>
    @endif

    @if($transaction->type->requiresAgentScreenshot())
        <section class="card workflow-card">
            <div class="step-heading">
                <span class="step-number">1</span>
                <div><h3>Agent-system screenshot</h3><p>Upload first. Agent and brand are never manually selected.</p></div>
            </div>

            @if(!$agentEvidence || in_array($agentEvidence->status,[App\Enums\EvidenceStatus::NeedsReupload,App\Enums\EvidenceStatus::Failed],true))
                <form method="post" action="{{ route('employee.transactions.agent-evidence',$transaction) }}" enctype="multipart/form-data" class="stack">
                    @csrf
                    <label class="upload-zone">
                        <span class="upload-icon">↑</span>
                        <b>Choose agent-system proof images</b>
                        <small>Upload up to {{ config('agent_audit.evidence.max_agent_proof_images_per_transaction',5) }} screenshots of the same transaction. AI reads them together.</small>
                        <input type="file" name="screenshots[]" accept="image/jpeg,image/png,image/webp" multiple required>
                    </label>
                    <button class="btn btn-primary btn-lg">Upload & Analyze</button>
                </form>
            @elseif($agentEvidence->status === App\Enums\EvidenceStatus::PendingEmployeeConfirmation)
                <div class="confidence-review">
                    <div class="alert info">
                        <b>Medium-confidence extraction</b><br>
                        Check the read-only values against the screenshot. Confirm if they are correct, or request a clearer screenshot.
                    </div>
                    <div class="evidence-review-grid">
                        <a href="{{ route('evidence.show',$agentEvidence) }}" target="_blank" class="evidence-image-link">
                            <img src="{{ route('evidence.show',$agentEvidence) }}" alt="Agent screenshot">
                        </a>
                        <div>
                            <div class="confidence-chip">{{ round(($agentEvidence->critical_confidence??0)*100,1) }}% confidence</div>
                            <div class="readonly-grid">
                                @foreach([
                                    'agent_id'=>'Agent ID',
                                    'agent_username'=>'Username',
                                    'amount'=>'Amount',
                                    'transaction_at'=>'Date / time',
                                    'balance_before'=>'Balance before',
                                    'balance_after'=>'Balance after',
                                    'transaction_reference'=>'System reference',
                                    'brand_hint'=>'Brand hint'
                                ] as $key=>$label)
                                    <div class="lock-field"><span>{{ strtoupper($label) }} 🔒</span><b>{{ data_get($agentEvidence->extracted,$key,'—') ?? '—' }}</b></div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="dual-actions">
                        <form method="post" action="{{ route('employee.evidence.confirm',$agentEvidence) }}">@csrf<button class="btn btn-success btn-lg">✓ Values Are Correct</button></form>
                        <form method="post" action="{{ route('employee.evidence.clearer',$agentEvidence) }}">@csrf<button class="btn btn-outline btn-lg">Upload Clearer Screenshot</button></form>
                    </div>
                </div>
            @else
                <div class="status-line">
                    <span><b>Proof images processed</b><small class="muted">Quality {{ $agentEvidence->quality_score?round($agentEvidence->quality_score*100).'%':'—' }}</small></span>
                    <span class="status-pill success">{{ str($agentEvidence->status->value)->replace('_',' ')->title() }}</span>
                </div>

                @if($agentEvidence->extracted)
                    <div class="readonly-grid" style="margin-top:12px">
                        @foreach([
                            'agent_id'=>'Agent ID',
                            'agent_username'=>'Username',
                            'amount'=>'Amount',
                            'transaction_at'=>'Date / time',
                            'balance_before'=>'Balance before',
                            'balance_after'=>'Balance after',
                            'transaction_reference'=>'System reference',
                            'brand_hint'=>'Brand hint'
                        ] as $key=>$label)
                            <div class="lock-field"><span>{{ strtoupper($label) }} 🔒</span><b>{{ data_get($agentEvidence->extracted,$key,'—') ?? '—' }}</b></div>
                        @endforeach
                    </div>
                @endif

                <a class="btn btn-sm btn-outline" style="margin-top:12px" href="{{ route('evidence.show',$agentEvidence) }}" target="_blank">View Screenshot</a>
            @endif
            @if($agentProofs->count() > 1)
                <div style="margin-top:14px">
                    <span class="eyebrow">{{ $agentProofs->count() }} RELATED PROOF IMAGES</span>
                    <div class="proof-gallery">
                        @foreach($agentProofs as $proof)
                            <a href="{{ route('evidence.show',$proof) }}" target="_blank" rel="noopener" class="proof-gallery-item">
                                <img src="{{ route('evidence.show',$proof) }}" alt="Agent proof {{ $loop->iteration }}" loading="lazy">
                                <span>Proof {{ $loop->iteration }}</span>
                            </a>
                        @endforeach
                    </div>
                    <p class="tiny muted">The AI combines these as evidence for one transaction. Amounts are not added together.</p>
                </div>
            @endif
        </section>
    @endif

    @if($transaction->type->requiresBankEvidence() && $transaction->agent_id)
        <section class="card workflow-card">
            <div class="step-heading">
                <span class="step-number">2</span>
                <div><h3>Bank payment screenshot(s)</h3><p>One or many receipts may be combined into this single top-up.</p></div>
            </div>

            @if(!$finalized)
                <form method="post" action="{{ route('employee.transactions.bank-evidence',$transaction) }}" enctype="multipart/form-data" class="stack">
                    @csrf
                    <label class="upload-zone compact-zone">
                        <span class="upload-icon">＋</span>
                        <b>Add payment screenshots</b>
                        <small>1–12 receipts. Each payment is independently validated.</small>
                        <input type="file" name="screenshots[]" multiple accept="image/*" required>
                    </label>
                    <button class="btn btn-primary">Upload Payment Evidence</button>
                </form>
            @endif

            @foreach($pendingBankEvidence as $evidence)
                <div class="confidence-review" style="margin-top:14px">
                    <div class="alert info"><b>Payment screenshot needs your confirmation</b> · {{ round(($evidence->critical_confidence??0)*100,1) }}% confidence</div>
                    <div class="evidence-review-grid">
                        <a href="{{ route('evidence.show',$evidence) }}" target="_blank" class="evidence-image-link"><img src="{{ route('evidence.show',$evidence) }}" alt="Bank screenshot"></a>
                        <div class="readonly-grid">
                            @foreach([
                                'from_bank'=>'From bank',
                                'to_bank'=>'To bank',
                                'sender_account'=>'Sender account',
                                'sender_name'=>'Sender name',
                                'receiver_account'=>'Receiver account',
                                'receiver_name'=>'Receiver name',
                                'amount'=>'Amount',
                                'transaction_id'=>'Transaction ID',
                                'transaction_at'=>'Date / time'
                            ] as $key=>$label)
                                <div class="lock-field"><span>{{ strtoupper($label) }} 🔒</span><b>{{ data_get($evidence->extracted,$key,'—') ?? '—' }}</b></div>
                            @endforeach
                        </div>
                    </div>
                    <div class="dual-actions">
                        <form method="post" action="{{ route('employee.evidence.confirm',$evidence) }}">@csrf<button class="btn btn-success">✓ Confirm Values</button></form>
                        <form method="post" action="{{ route('employee.evidence.clearer',$evidence) }}">@csrf<button class="btn btn-outline">Use Clearer Screenshot</button></form>
                    </div>
                </div>
            @endforeach

            <div class="payment-stack">
            @foreach($transaction->payments as $payment)
                <article class="payment-card {{ $payment->internal_status->value==='rejected'?'rejected':'' }}">
                    <div class="payment-head">
                        <div>
                            <b>{{ $payment->fromBank?->name ?? $payment->from_bank_raw ?? '?' }} <span class="arrow">→</span> {{ $payment->toBank?->name ?? $payment->to_bank_raw ?? '?' }}</b>
                            <div class="tiny muted">{{ $payment->transaction_id_raw }}</div>
                        </div>
                        <span class="status-pill {{ $payment->internal_status->value==='valid'?'success':($payment->internal_status->value==='rejected'?'danger':'warning') }}">{{ str($payment->internal_status->value)->title() }}</span>
                    </div>

                    <div class="readonly-grid">
                        <div class="lock-field"><span>{{ data_get($payment->evidenceFile?->extracted,'_receipt_intelligence.amount_needs_review',false) ? 'SENDER DEBIT · AMOUNT UNCONFIRMED' : 'TRANSFER AMOUNT' }} 🔒</span><b>{{ number_format((float)$payment->amount,2) }} ETB</b></div>
                        <div class="lock-field"><span>CHECK.ET STATUS</span><b>{{ str($payment->external_status?->value??'pending')->replace('_',' ')->title() }}</b></div>
                        <div class="lock-field"><span>LAST CHECK</span><b>{{ $payment->external_checked_at?->format('d M Y H:i') ?? 'Not checked yet' }}</b></div>
                        <div class="lock-field"><span>TIME 🔒</span><b>{{ $payment->transaction_at?->format('d M Y H:i') ?? '—' }}</b></div>
                        <div class="lock-field"><span>FROM 🔒</span><b>{{ $payment->sender_name ?: '—' }} · {{ $payment->sender_account ?: '—' }}</b></div>
                        <div class="lock-field"><span>TO 🔒</span><b>{{ $payment->receiver_name ?: '—' }} · {{ $payment->receiver_account ?: '—' }}</b></div>
                    </div>

                    @if(!$finalized && ($payment->internal_status === App\Enums\PaymentValidationStatus::Valid
                        || ($payment->internal_status === App\Enums\PaymentValidationStatus::Review
                            && in_array($payment->rejection_code, ['transfer_amount_unconfirmed','verification_amount_semantics_unknown'], true))))
                        <form method="post" action="{{ route('employee.transactions.payments.recheck-check-et',[$transaction,$payment]) }}" style="margin-top:10px">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm">↻ Recheck this receipt</button>
                        </form>
                    @endif
                    @if($payment->internal_status === App\Enums\PaymentValidationStatus::Review)
                        <div class="alert warning" style="margin-top:10px"><b>Not counted toward the top-up.</b> {{ $payment->rejection_reason ?: 'Bank verification or amount review is still required.' }}</div>
                    @elseif($payment->external_status === App\Enums\ExternalVerificationStatus::Unavailable)
                        <div class="alert warning" style="margin-top:10px"><b>Check.et unavailable or inconclusive.</b> Screenshot values are provisional. Use Recheck when the provider is available.</div>
                    @endif

                    @if($payment->internal_status->value==='rejected')
                        <div class="alert error" style="margin-top:10px">
                            <b>Payment rejected and excluded from valid total.</b><br>{{ $payment->rejection_reason }}
                        </div>
                    @elseif($payment->time_difference_minutes!==null && $payment->time_difference_minutes>60)
                        <div class="alert {{ $payment->risk_level?->value==='critical'?'error':'warning' }}" style="margin-top:10px">
                            Payment-to-top-up difference: <b>{{ $payment->time_difference_minutes }} minutes</b> · {{ strtoupper($payment->risk_level?->value??'') }}
                        </div>
                    @endif
                </article>
            @endforeach
            </div>
        </section>
    @endif

    @if($transaction->type === App\Enums\TransactionType::Withdrawal && $transaction->agent_id && !$finalized)
        <section class="card workflow-card danger-workflow">
            <div class="step-heading">
                <span class="step-number danger">!</span>
                <div><h3>Reason for balance removal</h3><p>This is a sensitive action and the reason is permanently recorded.</p></div>
            </div>

            <form method="post" action="{{ route('employee.transactions.reason',$transaction) }}" class="stack">
                @csrf
                <div class="field">
                    <label>Reason</label>
                    <select class="select" name="reason_code" id="withdrawalReason" required>
                        <option value="">Choose reason</option>
                        @foreach(config('agent_audit.withdrawal_reasons',[]) as $code=>$label)
                            <option value="{{ $code }}" @selected($transaction->withdrawal_reason_code===$code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Notes <span class="tiny muted">(required for Other)</span></label>
                    <textarea class="textarea" name="reason_note" placeholder="Add context when needed">{{ $transaction->withdrawal_reason_note }}</textarea>
                </div>
                <button class="btn btn-danger">Save Removal Reason</button>
            </form>
        </section>
    @endif

    @php($allowedCorrections=auth()->user()->permissions?->correction_fields??[])
    @if(count($allowedCorrections) && !$finalized)
        <section class="card workflow-card">
            <div class="card-title-row">
                <div><span class="eyebrow">REQUEST ONLY</span><h3>AI Correction Request</h3></div>
                <span class="lock-badge">🔒 Read-only</span>
            </div>
            <p class="small muted">You cannot directly edit extracted financial data. Submit only fields Admin has enabled for your account.</p>

            @if($agentEvidence && $agentEvidence->extracted)
                @php($agentFields=array_values(array_intersect($allowedCorrections,['agent_id','agent_username','agent_amount','agent_transaction_date','brand_hint'])))
                @if(count($agentFields))
                    <details class="evidence">
                        <summary><b>Agent-system extraction</b></summary>
                        <form method="post" action="{{ route('employee.transactions.corrections',$transaction) }}" class="stack" style="margin-top:10px">
                            @csrf
                            <input type="hidden" name="evidence_file_id" value="{{ $agentEvidence->id }}">
                            <select class="select" name="field" required>@foreach($agentFields as $field)<option value="{{ $field }}">{{ str($field)->replace('_',' ')->title() }}</option>@endforeach</select>
                            <input class="input" name="proposed_value" placeholder="Correct value visible in screenshot" required>
                            <textarea class="textarea" name="reason" placeholder="Why did AI read it incorrectly?" required></textarea>
                            <button class="btn btn-outline">Send to Admin Approval</button>
                        </form>
                    </details>
                @endif
            @endif

            @foreach($transaction->payments as $payment)
                @php($paymentFields=array_values(array_intersect($allowedCorrections,['amount','sender_bank','sender_account','sender_name','transaction_id','transaction_date'])))
                @if(count($paymentFields))
                    <details class="evidence" style="margin-top:8px">
                        <summary><b>Bank payment {{ $loop->iteration }}</b> · {{ $payment->transaction_id_raw }}</summary>
                        <form method="post" action="{{ route('employee.transactions.corrections',$transaction) }}" class="stack" style="margin-top:10px">
                            @csrf
                            <input type="hidden" name="payment_record_id" value="{{ $payment->id }}">
                            <input type="hidden" name="evidence_file_id" value="{{ $payment->evidence_file_id }}">
                            <select class="select" name="field" required>@foreach($paymentFields as $field)<option value="{{ $field }}">{{ str($field)->replace('_',' ')->title() }}</option>@endforeach</select>
                            <input class="input" name="proposed_value" placeholder="Correct value visible in screenshot" required>
                            <textarea class="textarea" name="reason" placeholder="Why did AI read it incorrectly?" required></textarea>
                            <button class="btn btn-outline">Send to Admin Approval</button>
                        </form>
                    </details>
                @endif
            @endforeach

            <div class="tiny muted" style="margin-top:10px">Receiver bank, receiving account, receiver name and global duplicate protection can never be corrected or bypassed.</div>
        </section>
    @endif

    <section class="card validation-card">
        <div class="card-title-row"><div><span class="eyebrow">LIVE VALIDATION</span><h3>Transaction Checks</h3></div></div>
        <div class="validation-list">
            <div><span>Agent / brand</span><b>{{ $transaction->agent_id?'Verified':'Pending' }}</b></div>
            @if($transaction->type->requiresBankEvidence())
                <div><span>Valid payment total</span><b>{{ number_format((float)$transaction->valid_payment_total,2) }} ETB</b></div>
                <div><span>Agent-system amount</span><b>{{ $transaction->amount!==null?number_format((float)$transaction->amount,2).' ETB':'Pending' }}</b></div>
                <div><span>Difference</span><b>{{ number_format((float)$transaction->difference,2) }} ETB</b></div>
                <div><span>Secondary verification</span><b>{{ str($transaction->external_verification_status?->value ?? 'pending')->replace('_',' ')->title() }}</b></div>
            @endif
            <div><span>Risk</span><b>{{ str($transaction->risk_level?->value ?? 'pending')->title() }}</b></div>
        </div>

        @if(($currentOutstanding??0)>0 && $transaction->type!==App\Enums\TransactionType::CreditRepayment)
            <div class="alert warning" style="margin-top:12px">
                <b>Agent already has outstanding credit.</b> This does not block a paid top-up or additional permitted credit.
            </div>
        @endif
    </section>

    @if($transaction->status===App\Enums\TransactionStatus::ReadyForReview)
        <section class="card final-confirm-card">
            <div><span class="eyebrow">FINAL STEP</span><h3>Complete Transaction</h3></div>
            <form method="post" action="{{ route('employee.transactions.finalize',$transaction) }}" class="stack">
                @csrf
                @if($transaction->risk_level===App\Enums\RiskLevel::Critical)
                    <div class="alert error"><b>Critical time difference.</b> Two explicit confirmations and your password are required.</div>
                    <label class="check"><input type="checkbox" name="critical_confirmation_1" value="1" required> I checked the payment details and intentionally use this older payment.</label>
                    <label class="check"><input type="checkbox" name="critical_confirmation_2" value="1" required> I understand this exceeds the critical time threshold.</label>
                    <input class="input" type="password" name="password" placeholder="Confirm your password" required>
                @endif
                <button class="btn btn-success btn-lg">Complete Transaction</button>
            </form>
        </section>
    @endif

    @if(!$finalized)
        <form method="post" action="{{ route('employee.transactions.cancel',$transaction) }}" style="margin-top:14px">
            @csrf
            <button class="btn btn-ghost">Cancel Draft</button>
        </form>
    @endif
</div>
@endsection
