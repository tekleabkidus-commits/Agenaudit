<?php

namespace App\Services\Banking;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;

/**
 * Read-only reference arithmetic. No guessed principal is ever written to a
 * PaymentRecord, valid_payment_total, bank_payment_total, or the credit ledger.
 */
final class AgentTopupReferenceService
{
    /**
     * All money returned as integer cents to avoid rounding drift.
     *
     * @return array<string,mixed>
     */
    public function analyze(Transaction $transaction): array
    {
        $transaction->loadMissing(['payments.evidenceFile', 'evidenceFiles']);

        $targetCents = $transaction->type === TransactionType::CreditRepayment || $transaction->amount === null
            ? null
            : $this->cents($transaction->amount);
        $payments = $transaction->payments;

        $readable = $payments->filter(fn ($payment) => $payment->internal_status === PaymentValidationStatus::Valid);
        $uncertain = $payments->filter(fn ($payment) => $payment->internal_status === PaymentValidationStatus::Review
            && in_array($payment->rejection_code, [
                'transfer_amount_unconfirmed',
                'verification_amount_semantics_unknown',
            ], true));
        $excluded = $payments->filter(fn ($payment) => $payment->internal_status === PaymentValidationStatus::Rejected
            || ($payment->internal_status === PaymentValidationStatus::Review && !$uncertain->contains('id', $payment->id)));

        $readableCents = $readable->sum(fn ($p) => $this->cents($p->amount));
        $verifiedCents = $readable
            ->filter(fn ($p) => $p->external_status === ExternalVerificationStatus::Passed)
            ->sum(fn ($p) => $this->cents($p->amount));

        // Outstanding bank screenshots are NOT known payments and cannot be
        // silently treated as zero when calculating the one unknown receipt.
        $openEvidence = $transaction->evidenceFiles
            ->filter(fn ($ev) => $ev->kind === EvidenceKind::BankPayment
                && $ev->superseded_by_id === null
                && in_array($ev->status, [
                    EvidenceStatus::Queued, EvidenceStatus::Processing,
                    EvidenceStatus::PendingEmployeeConfirmation,
                    EvidenceStatus::PendingAdminExtraction,
                    EvidenceStatus::Failed, EvidenceStatus::NeedsReupload,
                ], true))->count();

        $knownDifferenceCents = $targetCents === null ? null : $readableCents - $targetCents;
        $remainderCents = $targetCents === null ? null : $targetCents - $readableCents;
        $suggestion = null;
        $reason = null;

        if ($targetCents === null) {
            $reason = 'A fixed agent top-up reference amount is not available for this transaction.';
        } elseif ($uncertain->isEmpty()) {
            $reason = $readableCents === $targetCents
                ? 'Readable receipt amounts match the agent top-up; payment settlement may still be unverified.'
                : 'Readable receipt amounts do not match the agent top-up.';
        } elseif ($uncertain->count() !== 1 || $excluded->isNotEmpty() || $openEvidence > 0
            || $payments->count() !== $readable->count() + $uncertain->count()) {
            $reason = 'Multiple uncertain or excluded receipts or unprocessed screenshots prevent allocating the remaining amount to any one receipt.';
        } elseif ($remainderCents === null || $remainderCents <= 0) {
            $reason = 'The readable receipts already meet or exceed the agent top-up. Do not infer additional amounts.';
        } else {
            $payment = $uncertain->first();
            $info = $payment->evidenceFile?->extracted ?? [];
            $role = (string)data_get($info,'_receipt_intelligence.amount_role',
                data_get($info,'amount_role','unknown'));
            $payerDebit = data_get($info,'_receipt_intelligence.total_debited');

            if (!is_numeric($payerDebit) && $role === 'total_debit') {
                $payerDebit = $payment->amount;
            }

            if ($role !== 'total_debit' || !is_numeric($payerDebit)) {
                $reason = 'The remaining amount is calculable, but the uncertain receipt does not clearly show a payer debit that can be compared.';
            } elseif ($this->cents($payerDebit) < $remainderCents) {
                $reason = 'The remaining amount exceeds the visible payer debit; the receipts do not reconcile. Admin review required.';
            } else {
                $suggestion = [
                    'payment_id'=>$payment->id,
                    'candidate_principal_cents'=>$remainderCents,
                    'payer_debit_cents'=>$this->cents($payerDebit),
                    'implied_charges_cents'=>$this->cents($payerDebit) - $remainderCents,
                    'reference_only'=>true,
                ];
                $reason = 'Reference-based arithmetic only. These amounts are not authenticated bank transfers or proven service fees.';
            }
        }

        return [
            'target_cents'=>$targetCents,
            'readable_principal_cents'=>$readableCents,
            'check_et_confirmed_cents'=>$verifiedCents,
            'remainder_cents'=>$remainderCents,
            'known_difference_cents'=>$knownDifferenceCents,
            'readable_count'=>$readable->count(),
            'check_et_confirmed_count'=>$readable->filter(fn ($p) => $p->external_status === ExternalVerificationStatus::Passed)->count(),
            'uncertain_count'=>$uncertain->count(),
            'excluded_count'=>$excluded->count(),
            'open_evidence_count'=>$openEvidence,
            'candidate'=>$suggestion,
            'explanation'=>$reason,
        ];
    }

    private function cents(mixed $value): int
    {
        return (int)round((float)$value*100);
    }
}
