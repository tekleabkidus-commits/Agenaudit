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
        if ($existing && $existing->transaction_id !== $transaction->id) {
            $this->createRejectedDuplicateRecord($transaction, $evidence, $extracted, $rawReference);
            throw new HardRejectException('duplicate_transaction_id', 'This bank transaction ID has already been used anywhere on the platform.');
        }
        if ($existing && $existing->transaction_id === $transaction->id && $existing->internal_status !== PaymentValidationStatus::Review) {
            throw new HardRejectException('duplicate_transaction_id', 'The same bank transaction ID was attached more than once.');
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
            if (str_contains(strtolower($e->getMessage()), 'unique')) throw new HardRejectException('duplicate_transaction_id', 'This bank transaction ID is already reserved on the platform.');
            throw $e;
        }

        if ($match->status === 'no_match') {
            $payment->update([
                'internal_status'=>PaymentValidationStatus::Rejected,'external_status'=>ExternalVerificationStatus::NotRequired,
                'rejection_code'=>'receiving_account_mismatch','rejection_reason'=>$match->reason,
            ]);
            throw new HardRejectException('receiving_account_mismatch', $match->reason ?? 'Receiving account is not approved.');
        }
        if (in_array($match->status, ['ambiguous','insufficient'], true)) {
            $payment->update([
                'internal_status'=>PaymentValidationStatus::Review,'external_status'=>ExternalVerificationStatus::NotRequired,
                'rejection_code'=>'receiving_account_unverifiable','rejection_reason'=>$match->reason,
            ]);
            throw new ClearerScreenshotRequiredException('receiving_account_unverifiable', $match->reason ?? 'Receiving account cannot be uniquely verified.');
        }

        $payment->update(['internal_status'=>PaymentValidationStatus::Valid]);

        $external = $this->checkEt->verify($payment->fresh(['toBank','receivingAccount']));
        $externalStatus = match ($external->status) {
            'passed' => ExternalVerificationStatus::Passed,
            'failed' => ExternalVerificationStatus::Failed,
            'unavailable' => ExternalVerificationStatus::Unavailable,
            default => ExternalVerificationStatus::Disabled,
        };
        $payment->update([
            'external_status'=>$externalStatus,
            'external_response'=>$external->payload ?: null,
            'external_request_keys'=>$external->requestKeys ?: null,
            'external_checked_at'=>in_array($externalStatus, [ExternalVerificationStatus::Passed,ExternalVerificationStatus::Failed,ExternalVerificationStatus::Unavailable], true) ? now() : null,
        ]);

        return $payment->fresh();
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

    public function retryExternal(PaymentRecord $payment): PaymentRecord
    {
        if ($payment->internal_status !== PaymentValidationStatus::Valid) throw new \RuntimeException('Only internally valid payments can be sent for secondary verification.');
        $payment->loadMissing(['toBank','receivingAccount']);
        $external = $this->checkEt->verify($payment);
        $status = match ($external->status) {
            'passed' => ExternalVerificationStatus::Passed,
            'failed' => ExternalVerificationStatus::Failed,
            'unavailable' => ExternalVerificationStatus::Unavailable,
            default => ExternalVerificationStatus::Disabled,
        };
        $payment->update([
            'external_status'=>$status, 'external_response'=>$external->payload ?: null, 'external_request_keys'=>$external->requestKeys ?: null,
            'external_checked_at'=>in_array($status,[ExternalVerificationStatus::Passed,ExternalVerificationStatus::Failed,ExternalVerificationStatus::Unavailable],true)?now():null,
        ]);
        return $payment->fresh();
    }

    private function createRejectedDuplicateRecord(Transaction $transaction, EvidenceFile $evidence, array $extracted, string $rawReference): void
    {
        PaymentRecord::create([
            'transaction_id'=>$transaction->id,'evidence_file_id'=>$evidence->id,
            'from_bank_raw'=>data_get($extracted,'from_bank'),'to_bank_raw'=>data_get($extracted,'to_bank'),
            'sender_account'=>data_get($extracted,'sender_account'),'sender_name'=>data_get($extracted,'sender_name'),
            'receiver_account'=>data_get($extracted,'receiver_account'),'receiver_name'=>data_get($extracted,'receiver_name'),
            'amount'=>(float) data_get($extracted,'amount',0),'transaction_id_raw'=>$rawReference,
            'normalized_transaction_id'=>null,'transaction_at'=>data_get($extracted,'transaction_at'),
            'internal_status'=>PaymentValidationStatus::Rejected,'external_status'=>ExternalVerificationStatus::NotRequired,
            'rejection_code'=>'duplicate_transaction_id','rejection_reason'=>'Transaction ID already exists on platform.',
        ]);
    }
}
