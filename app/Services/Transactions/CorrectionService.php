<?php

namespace App\Services\Transactions;

use App\Enums\CorrectionStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\HardRejectException;
use App\Models\Bank;
use App\Models\CorrectionRequest;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Banking\BankResolver;
use App\Services\Banking\PaymentVerificationService;
use App\Support\Normalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CorrectionService
{
    public function __construct(private TransactionWorkflowService $workflow, private BankResolver $banks, private PaymentVerificationService $paymentVerification, private AuditLogger $audit) {}

    public function request(User $employee, Transaction $transaction, string $field, mixed $proposed, string $reason, ?PaymentRecord $payment = null, ?EvidenceFile $evidence = null): CorrectionRequest
    {
        if (in_array($field, config('agent_audit.hard_non_correctable_fields', []), true)) throw new RuntimeException('This field is permanently non-correctable. Upload a valid screenshot instead.');
        if (!in_array($field, config('agent_audit.correction_fields', []), true)) throw new RuntimeException('This field is not correction-enabled by the platform.');
        if (!$employee->permissions?->canRequestCorrection($field)) throw new RuntimeException('Admin has not enabled this correction type for this employee.');
        if ($transaction->employee_id !== $employee->id) throw new RuntimeException('Employees may only request corrections on their own transactions.');

        $aiValue = $this->currentValue($field, $transaction, $payment);
        $request = CorrectionRequest::create([
            'transaction_id'=>$transaction->id,'payment_record_id'=>$payment?->id,'evidence_file_id'=>$evidence?->id,
            'requested_by'=>$employee->id,'field_name'=>$field,'ai_value'=>$aiValue,'proposed_value'=>$proposed,'reason'=>$reason,'status'=>CorrectionStatus::Pending,
        ]);
        $transaction->update(['status'=>TransactionStatus::PendingCorrectionApproval,'review_reason'=>'Employee correction request pending Admin approval.']);
        $this->audit->log('correction.requested', $request, null, ['field'=>$field,'ai_value'=>$aiValue,'proposed'=>$proposed], [], $employee);
        return $request;
    }

    public function approve(CorrectionRequest $request, User $admin, ?string $note = null): CorrectionRequest
    {
        return DB::transaction(function () use ($request, $admin, $note) {
        if ($request->status !== CorrectionStatus::Pending) throw new RuntimeException('Correction request is no longer pending.');
        if (in_array($request->field_name, config('agent_audit.hard_non_correctable_fields', []), true)) throw new RuntimeException('Hard receiving-account fields can never be corrected.');

        $transaction = $request->transaction;
        $payment = $request->paymentRecord;
        $value = $request->proposed_value;

        match ($request->field_name) {
            'amount' => $this->updatePayment($payment, ['amount'=>(float)$value]),
            'sender_name' => $this->updatePayment($payment, ['sender_name'=>(string)$value]),
            'sender_account' => $this->updatePayment($payment, ['sender_account'=>(string)$value]),
            'sender_bank' => $this->updateSenderBank($payment, (string)$value),
            'transaction_date' => $this->updatePayment($payment, ['transaction_at'=>CarbonImmutable::parse((string)$value)]),
            'transaction_id' => $this->updateTransactionId($payment, (string)$value),
            'agent_amount' => $transaction->update(['amount'=>(float)$value]),
            'agent_transaction_date' => $transaction->update(['agent_system_at'=>CarbonImmutable::parse((string)$value)]),
            'agent_id', 'agent_username', 'brand_hint' => $this->approveAgentField($request, $value),
            default => throw new RuntimeException('Unsupported correction field.'),
        };

        if ($payment && in_array($request->field_name, ['amount','sender_name','sender_account','sender_bank','transaction_date','transaction_id'], true)) {
            $this->paymentVerification->refreshAfterCorrection($payment->fresh());
        }

        $request->update(['status'=>CorrectionStatus::Approved,'reviewed_by'=>$admin->id,'reviewed_at'=>now(),'admin_note'=>$note]);
        $this->audit->log('correction.approved', $request, ['ai_value'=>$request->ai_value], ['proposed_value'=>$value,'note'=>$note], [], $admin);
        $this->workflow->recalculate($transaction->fresh());
        return $request->fresh();
        }, 3);
    }

    public function reject(CorrectionRequest $request, User $admin, string $note, bool $needsScreenshot = false): CorrectionRequest
    {
        if ($request->status !== CorrectionStatus::Pending) throw new RuntimeException('Correction request is no longer pending.');
        $request->update([
            'status'=>$needsScreenshot ? CorrectionStatus::NeedsNewScreenshot : CorrectionStatus::Rejected,
            'reviewed_by'=>$admin->id,'reviewed_at'=>now(),'admin_note'=>$note,
        ]);
        $request->transaction->update([
            'status'=>$needsScreenshot ? TransactionStatus::NeedsClearerScreenshot : TransactionStatus::PendingAdminReview,
            'review_reason'=>$note,
        ]);
        $this->audit->log('correction.rejected', $request, null, ['note'=>$note,'needs_screenshot'=>$needsScreenshot], [], $admin);
        return $request->fresh();
    }

    private function updatePayment(?PaymentRecord $payment, array $attrs): void
    {
        if (!$payment) throw new RuntimeException('This correction requires a bank payment record.');
        $payment->update($attrs);
    }

    private function updateSenderBank(?PaymentRecord $payment, string $raw): void
    {
        if (!$payment) throw new RuntimeException('This correction requires a bank payment record.');
        $bank = $this->banks->resolve($raw);
        if (!$bank) throw new RuntimeException('Proposed sender bank is not in the bank catalog.');
        $payment->update(['from_bank_id'=>$bank->id,'from_bank_raw'=>$raw]);
    }

    private function updateTransactionId(?PaymentRecord $payment, string $raw): void
    {
        if (!$payment) throw new RuntimeException('This correction requires a bank payment record.');
        $normalized = Normalizer::transactionId($raw);
        if (!$normalized) throw new RuntimeException('Proposed transaction ID is invalid.');
        if (PaymentRecord::where('normalized_transaction_id',$normalized)->whereKeyNot($payment->id)->exists()) {
            throw new HardRejectException('duplicate_transaction_id', 'Corrected bank transaction ID already exists anywhere on the platform.');
        }
        $payment->update(['transaction_id_raw'=>$raw,'normalized_transaction_id'=>$normalized]);
    }

    private function approveAgentField(CorrectionRequest $request, mixed $value): void
    {
        $evidence = $request->evidenceFile;
        if (!$evidence) throw new RuntimeException('Agent correction requires its source evidence.');
        $extracted = $evidence->extracted ?? [];
        $key = match ($request->field_name) { 'agent_id'=>'agent_id','agent_username'=>'agent_username','brand_hint'=>'brand_hint' };
        $extracted[$key] = $value;
        $evidence->update(['extracted'=>$extracted]);
        app(AgentIdentityService::class)->apply($request->transaction, $extracted);
    }

    private function currentValue(string $field, Transaction $transaction, ?PaymentRecord $payment): mixed
    {
        return match ($field) {
            'amount' => $payment?->amount,
            'sender_name' => $payment?->sender_name,
            'sender_account' => $payment?->sender_account,
            'sender_bank' => $payment?->from_bank_raw,
            'transaction_date' => $payment?->transaction_at?->toIso8601String(),
            'transaction_id' => $payment?->transaction_id_raw,
            'agent_amount' => $transaction->amount,
            'agent_transaction_date' => $transaction->agent_system_at?->toIso8601String(),
            default => data_get($requestEvidence = $transaction->evidenceFiles()->where('kind','agent_system')->latest()->first()?->extracted, $field),
        };
    }
}
