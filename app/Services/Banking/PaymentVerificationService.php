<?php

namespace App\Services\Banking;

use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Exceptions\ClearerScreenshotRequiredException;
use App\Exceptions\HardRejectException;
use App\Exceptions\ReviewRequiredException;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Services\Integrations\CheckEtClient;
use App\Services\SettingsService;
use App\Support\Normalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Throwable;

class PaymentVerificationService
{
    public function __construct(
        private BankResolver $banks,
        private ReceivingAccountMatcher $accounts,
        private TimeRiskService $timeRisk,
        private CheckEtClient $checkEt,
        private SettingsService $settings,
    ) {}

    public function apply(Transaction $transaction, EvidenceFile $evidence, array $extracted): PaymentRecord
    {
        if (!$transaction->brand_id || !$transaction->agent_id) throw new ReviewRequiredException('agent_required_first', 'Agent must be identified before bank evidence is processed.');

        $rawReference = trim((string) data_get($extracted, 'transaction_id'));
        $normalizedReference = Normalizer::transactionId($rawReference);
        if (!$normalizedReference) throw new ClearerScreenshotRequiredException('transaction_id_unreadable', 'Bank transaction ID is not readable.');

        $existing = PaymentRecord::where('normalized_transaction_id', $normalizedReference)->first();

        if ($existing && $existing->evidence_file_id !== $evidence->id) {
            return $this->createRejectedDuplicateRecord(
                $transaction,
                $evidence,
                $extracted,
                $rawReference,
                $existing
            );
        }

        $toBankRaw = (string) data_get($extracted, 'to_bank');
        $fromBankRaw = (string) data_get($extracted, 'from_bank');
        $toBank = $this->banks->resolve($toBankRaw);
        $fromBank = $this->banks->resolve($fromBankRaw);
        if (!$toBank) throw new ReviewRequiredException('unknown_receiving_bank', 'Receiving bank could not be matched to the configured bank catalog.');

        $receiverAccount = (string) data_get($extracted, 'receiver_account');
        $receiverName = (string) data_get($extracted, 'receiver_name');
        $match = $this->accounts->match($transaction->brand, $toBank, $receiverAccount, $receiverName);

        $amount = round((float) data_get($extracted, 'amount'), 2);
        if ($amount <= 0) throw new ClearerScreenshotRequiredException('invalid_payment_amount', 'Payment amount is not readable or invalid.');
        try { $transactionAt = CarbonImmutable::parse((string) data_get($extracted, 'transaction_at')); }
        catch (Throwable) { throw new ClearerScreenshotRequiredException('invalid_payment_time', 'Bank transaction time is not readable.'); }

        [$risk, $minutes] = $this->timeRisk->evaluate($transactionAt, $transaction->agent_system_at ?? $transactionAt);

        $attributes = [
            'transaction_id'=>$transaction->id,'evidence_file_id'=>$evidence->id,
            'from_bank_id'=>$fromBank?->id,'to_bank_id'=>$toBank->id,'receiving_account_id'=>$match->account?->id,
            'from_bank_raw'=>$fromBankRaw,'to_bank_raw'=>$toBankRaw,
            'sender_account'=>data_get($extracted, 'sender_account'),'sender_name'=>data_get($extracted, 'sender_name'),
            'receiver_account'=>$receiverAccount,'receiver_name'=>$receiverName,'amount'=>$amount,
            'transaction_id_raw'=>$rawReference,'normalized_transaction_id'=>$normalizedReference,'transaction_at'=>$transactionAt,
            'account_match_method'=>$match->method,'account_match_confidence'=>$match->confidence ?: null,
            'internal_status'=>PaymentValidationStatus::Pending,'external_status'=>ExternalVerificationStatus::Pending,
            'risk_level'=>$risk,'time_difference_minutes'=>$minutes,
        ];

        try {
            if ($existing) {
                $existing->evidenceFile?->update(['superseded_by_id'=>$evidence->id]);
                $existing->update($attributes);
                $payment = $existing->fresh(['toBank','receivingAccount']);
            } else {
                $payment = PaymentRecord::create($attributes)->load(['toBank','receivingAccount']);
            }
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                $original = PaymentRecord::where('normalized_transaction_id', $normalizedReference)->first();
                if ($original) {
                    return $this->createRejectedDuplicateRecord(
                        $transaction,
                        $evidence,
                        $extracted,
                        $rawReference,
                        $original
                    );
                }
            }
            throw $e;
        }

        // A receipt that visibly states FAILED, CANCELLED or PENDING is not proof
        // of settled money, even when its account and reference look plausible.
        $receiptStatus = mb_strtolower(trim((string) data_get($extracted, 'status', '')));
        $failedMarkers = ['failed', 'declined', 'rejected', 'cancelled', 'canceled', 'unsuccessful', 'reversed', 'voided'];
        $pendingMarkers = ['pending', 'processing', 'initiated', 'awaiting', 'in progress', 'on hold'];

        foreach ($failedMarkers as $marker) {
            if ($receiptStatus !== '' && str_contains($receiptStatus, $marker)) {
                $payment->update([
                    'internal_status' => PaymentValidationStatus::Rejected,
                    'external_status' => ExternalVerificationStatus::NotRequired,
                    'rejection_code' => 'payment_not_successful',
                    'rejection_reason' => 'Receipt explicitly shows an unsuccessful or reversed payment.',
                ]);
                return $payment->fresh();
            }
        }

        foreach ($pendingMarkers as $marker) {
            if ($receiptStatus !== '' && str_contains($receiptStatus, $marker)) {
                $payment->update([
                    'internal_status' => PaymentValidationStatus::Review,
                    'external_status' => ExternalVerificationStatus::NotRequired,
                    'rejection_code' => 'payment_not_completed',
                    'rejection_reason' => 'Receipt does not yet show a completed payment.',
                ]);
                return $payment->fresh();
            }
        }

        if ($match->status === 'no_match') {
            $payment->update([
                'internal_status'=>PaymentValidationStatus::Rejected,
                'external_status'=>ExternalVerificationStatus::NotRequired,
                'rejection_code'=>'receiving_account_mismatch',
                'rejection_reason'=>$match->reason,
            ]);
            return $payment->fresh();
        }
        if (in_array($match->status, ['ambiguous','insufficient'], true)) {
            $payment->update([
                'internal_status'=>PaymentValidationStatus::Review,'external_status'=>ExternalVerificationStatus::NotRequired,
                'rejection_code'=>'receiving_account_unverifiable','rejection_reason'=>$match->reason,
            ]);
            throw new ClearerScreenshotRequiredException('receiving_account_unverifiable', $match->reason ?? 'Receiving account cannot be uniquely verified.');
        }

        $amountUncertain = (bool) data_get($extracted, '_receipt_intelligence.amount_needs_review', false);
        // A debited amount of uncertain meaning is NOT counted toward the
        // agent top-up, even when the total happens to match.
        $payment->update([
            'internal_status' => $amountUncertain ? PaymentValidationStatus::Review : PaymentValidationStatus::Valid,
        ]);

        return $this->retryExternal(
            $payment->fresh(['fromBank','toBank','receivingAccount','evidenceFile']),
            $amountUncertain
        );
    }

    public function refreshAfterCorrection(PaymentRecord $payment): PaymentRecord
    {
        if ($payment->internal_status !== PaymentValidationStatus::Valid) {
            throw new \RuntimeException('Only internally valid payments can be refreshed after a correction.');
        }

        $payment->loadMissing(['transaction', 'toBank', 'receivingAccount']);
        $transaction = $payment->transaction;
        if ($payment->transaction_at && $transaction?->agent_system_at) {
            [$risk, $minutes] = $this->timeRisk->evaluate($payment->transaction_at, $transaction->agent_system_at);
            $payment->update(['risk_level' => $risk, 'time_difference_minutes' => $minutes]);
        }

        // A corrected amount/reference/date invalidates the previous external check.
        $payment->update([
            'external_status' => ExternalVerificationStatus::Pending,
            'external_response' => null,
            'external_request_keys' => null,
            'external_checked_at' => null,
        ]);

        return $this->retryExternal($payment->fresh(['toBank', 'receivingAccount']));
    }

    /**
     * Retry just the external verification, using the ORIGINAL extracted
     * screenshot data. No new payment, receipt or transaction is created.
     *
     * Also supports previously unresolved fee-inclusive payer debits:
     * an independently verified transfer amount can repair them after
     * a provider outage. Duplicate / mismatched-account rejections cannot.
     */
    public function retryExternal(PaymentRecord $payment, ?bool $amountUncertain = null): PaymentRecord
    {
        if ($payment->internal_status === PaymentValidationStatus::Rejected) {
            throw new \RuntimeException('Rejected financial evidence cannot be rechecked or reactivated.');
        }

        $payment->loadMissing(['fromBank','toBank','receivingAccount','evidenceFile']);
        $code = (string)($payment->rejection_code ?? '');
        $retryableReview = in_array($code, [
            'transfer_amount_unconfirmed',
            'verification_amount_semantics_unknown',
        ], true);

        if ($payment->internal_status === PaymentValidationStatus::Review && !$retryableReview
            && $amountUncertain !== true) {
            throw new \RuntimeException('This receipt requires clearer evidence or Admin review before external rechecking.');
        }

        $amountUncertain ??= (bool)data_get(
            $payment->evidenceFile?->extracted,
            '_receipt_intelligence.amount_needs_review',
            false
        );

        // An ambiguous screenshot amount can only be resolved by verified
        // issuer-side transfer-principal data from a calibrated provider.
        $external = $this->checkEt->verify($payment, $amountUncertain);
        $status = match ($external->status) {
            'passed' => ExternalVerificationStatus::Passed,
            'failed' => ExternalVerificationStatus::Failed,
            'unavailable', 'ambiguous' => ExternalVerificationStatus::Unavailable,
            default => ExternalVerificationStatus::Disabled,
        };

        $updates = [
            'external_status'=>$status,
            'external_response'=>$external->payload ?: null,
            'external_request_keys'=>$external->requestKeys ?: null,
            'external_checked_at'=>now(),
        ];

        if ($amountUncertain) {
            $verifiedAmount = data_get($external->payload, 'data.receipt.amount');
            if ($external->status === 'passed' && is_numeric($verifiedAmount)
                && is_finite((float)$verifiedAmount) && (float)$verifiedAmount > 0) {
                $updates['amount'] = round((float)$verifiedAmount, 2);
                $updates['internal_status'] = PaymentValidationStatus::Valid;
                $updates['rejection_code'] = null;
                $updates['rejection_reason'] = null;
            } else {
                $updates['internal_status'] = PaymentValidationStatus::Review;
                $updates['rejection_code'] = 'transfer_amount_unconfirmed';
                $updates['rejection_reason'] = 'Displayed payer debit may include fees. Check.et has not confirmed a fee-exclusive transfer amount. Retry later or ask Admin to check official receiving-bank evidence.';
            }
        } elseif ($external->status === 'ambiguous') {
            $updates['internal_status'] = PaymentValidationStatus::Review;
            $updates['rejection_code'] = 'verification_amount_semantics_unknown';
            $updates['rejection_reason'] = 'Check.et amount differs from screenshot; provider fee semantics have not been confirmed. Retry after verification is clarified.';
        } elseif ($external->status === 'passed' && $retryableReview) {
            // Restores a previously known explicit transfer amount once the
            // provider independently confirms it.
            $updates['internal_status'] = PaymentValidationStatus::Valid;
            $updates['rejection_code'] = null;
            $updates['rejection_reason'] = null;
        } elseif ($external->status === 'failed' && $retryableReview) {
            $updates['internal_status'] = PaymentValidationStatus::Review;
            $updates['rejection_code'] = 'external_verification_failed';
            $updates['rejection_reason'] = 'The external verifier could not validate this receipt. Admin investigation is required.';
        }

        $payment->update($updates);
        return $payment->fresh();
    }

    private function createRejectedDuplicateRecord(
        Transaction $transaction,
        EvidenceFile $evidence,
        array $extracted,
        string $rawReference,
        PaymentRecord $original
    ): PaymentRecord {
        $record = PaymentRecord::updateOrCreate(
            ['evidence_file_id'=>$evidence->id],
            [
                'transaction_id'=>$transaction->id,
                'from_bank_raw'=>data_get($extracted,'from_bank'),
                'to_bank_raw'=>data_get($extracted,'to_bank'),
                'sender_account'=>data_get($extracted,'sender_account'),
                'sender_name'=>data_get($extracted,'sender_name'),
                'receiver_account'=>data_get($extracted,'receiver_account'),
                'receiver_name'=>data_get($extracted,'receiver_name'),
                'amount'=>(float) data_get($extracted,'amount',0),
                'transaction_id_raw'=>$rawReference,
                'normalized_transaction_id'=>null,
                'duplicate_of_payment_id'=>$original->id,
                'transaction_at'=>data_get($extracted,'transaction_at'),
                'internal_status'=>PaymentValidationStatus::Rejected,
                'external_status'=>ExternalVerificationStatus::NotRequired,
                'rejection_code'=>'duplicate_transaction_id',
                'rejection_reason'=>'Transaction ID already exists on platform.',
            ]
        );

        return $record->fresh(['duplicateOf.transaction','evidenceFile']);
    }

}
