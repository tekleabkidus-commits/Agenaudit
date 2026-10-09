<?php

namespace App\Http\Controllers\Employee;

use App\Enums\PaymentValidationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RecheckExternalPaymentsJob;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Services\Transactions\TransactionEventLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckEtRecheckController extends Controller
{
    public function all(Request $request, Transaction $transaction, TransactionEventLogger $events): RedirectResponse
    {
        $this->authorize('update', $transaction);
        abort_unless($transaction->type->requiresBankEvidence(), 422);

        $count = $transaction->payments()->where(function ($q) {
            $q->where('internal_status', PaymentValidationStatus::Valid->value)
                ->orWhere(function ($pending) {
                    $pending->where('internal_status', PaymentValidationStatus::Review->value)
                        ->whereIn('rejection_code', [
                            'transfer_amount_unconfirmed',
                            'verification_amount_semantics_unknown',
                        ]);
                });
        })->count();

        if (!$count) return back()->withErrors(['recheck'=>'No eligible bank receipts are ready for external rechecking.']);

        $events->add($transaction,'check_et_recheck_requested',
            'Employee requested a new external verification of bank receipts.',
            ['receipt_count'=>$count],$request->user());
        RecheckExternalPaymentsJob::dispatch($transaction->id, null, $request->user()->id);

        return back()->with('success','Check.et recheck queued for '.$count.' receipt(s). Refresh this page later to see the latest result.');
    }

    public function one(Request $request, Transaction $transaction, PaymentRecord $payment, TransactionEventLogger $events): RedirectResponse
    {
        $this->authorize('update', $transaction);
        abort_unless($transaction->type->requiresBankEvidence() && $payment->transaction_id === $transaction->id, 404);
        $canRetry = $payment->internal_status === PaymentValidationStatus::Valid
            || ($payment->internal_status === PaymentValidationStatus::Review
                && in_array($payment->rejection_code, [
                    'transfer_amount_unconfirmed',
                    'verification_amount_semantics_unknown',
                ], true));
        abort_unless($canRetry, 422, 'This receipt needs clearer evidence or Admin review; external rechecking cannot reverse a rejected receipt.');

        $events->add($transaction,'check_et_recheck_requested',
            'Employee requested external verification of a single bank receipt.',
            ['payment_id'=>$payment->id],$request->user());
        RecheckExternalPaymentsJob::dispatch($transaction->id, $payment->id, $request->user()->id);

        return back()->with('success','Check.et recheck queued for this receipt. Refresh the page to see its verification result.');
    }
}
