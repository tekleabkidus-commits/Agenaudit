<?php

namespace App\Jobs;

use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionStatus;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\PaymentVerificationService;
use App\Services\Transactions\TransactionEventLogger;
use App\Services\Transactions\TransactionWorkflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Rerun bank-side verification without AI extraction or creating receipts.
 * One queue job per transaction serializes all of its payment checks.
 */
class RecheckExternalPaymentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public int $transactionId,
        public ?int $paymentId = null,
        public ?int $requestedById = null
    ) {
        // Existing evidence queue worker is also able to process these jobs.
        $this->onQueue('evidence');
    }

    public function handle(
        PaymentVerificationService $verifier,
        TransactionWorkflowService $workflow,
        TransactionEventLogger $events
    ): void {
        $lock = Cache::lock('agenaudit:bank-recheck:'.$this->transactionId, 180);
        if (!$lock->get()) {
            $this->release(5);
            return;
        }

        try {
            $transaction = Transaction::find($this->transactionId);
            if (!$transaction || in_array($transaction->status, [
                TransactionStatus::Completed,
                TransactionStatus::Rejected,
                TransactionStatus::Cancelled,
            ], true)) return;

            if (!$transaction->type->requiresBankEvidence()) return;

            $actor = $this->requestedById ? User::find($this->requestedById) : null;
            $payments = $transaction->payments()
                ->when($this->paymentId, fn ($q) => $q->whereKey($this->paymentId))
                ->orderBy('id')->get();

            $attempted = 0;
            $passed = 0;
            $stillWaiting = 0;

            foreach ($payments as $payment) {
                if (in_array($transaction->fresh()->status, [
                    TransactionStatus::Completed,
                    TransactionStatus::Rejected,
                    TransactionStatus::Cancelled,
                ], true)) break;

                // A rejected duplicate, wrong receiver or cancelled payment
                // can NEVER be made valid through a Check.et recheck.
                $canRetry = $payment->internal_status === PaymentValidationStatus::Valid
                    || ($payment->internal_status === PaymentValidationStatus::Review
                        && in_array($payment->rejection_code, [
                            'transfer_amount_unconfirmed',
                            'verification_amount_semantics_unknown',
                        ], true));
                if (!$canRetry) continue;

                $attempted++;
                try {
                    $result = $verifier->retryExternal($payment);
                    if ($result->internal_status === PaymentValidationStatus::Valid
                        && $result->external_status?->value === 'passed') $passed++;
                    else $stillWaiting++;
                } catch (Throwable $error) {
                    report($error);
                    $stillWaiting++;
                }
            }

            $workflow->recalculate($transaction->fresh());
            $events->add($transaction, 'check_et_rechecked',
                'Check.et recheck finished; pending payments remain pending until confirmed.',
                [
                    'payment_id'=>$this->paymentId,
                    'attempted'=>$attempted,
                    'passed'=>$passed,
                    'still_waiting'=>$stillWaiting,
                ],
                $actor
            );
        } finally {
            $lock->release();
        }
    }
}
