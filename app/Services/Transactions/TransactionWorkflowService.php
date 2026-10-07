<?php

namespace App\Services\Transactions;

use App\Enums\CreditEntryType;
use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\HardRejectException;
use App\Models\Agent;
use App\Models\Transaction;
use App\Models\TransactionConfirmation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TransactionWorkflowService
{
    public function __construct(
        private CreditLedgerService $credits,
        private TransactionEventLogger $events,
        private AuditLogger $audit,
        private SettingsService $settings,
    ) {}

    public function create(User $employee, TransactionType $type, ?Agent $repaymentAgent = null): Transaction
    {
        $attrs = [
            'reference'=>(string) Str::uuid(),'employee_id'=>$employee->id,'type'=>$type,'status'=>TransactionStatus::Draft,
            'external_verification_status'=>ExternalVerificationStatus::NotRequired,
        ];
        if ($type === TransactionType::CreditRepayment) {
            if (!$repaymentAgent) throw new RuntimeException('Credit repayment requires an outstanding-credit agent.');
            $outstanding = $this->credits->outstanding($repaymentAgent);
            if ($outstanding <= 0) throw new HardRejectException('no_outstanding_credit', 'This agent has no outstanding credit.');
            $attrs += ['agent_id'=>$repaymentAgent->id,'brand_id'=>$repaymentAgent->brand_id,'outstanding_credit_at_time'=>$outstanding];
        }
        $transaction = Transaction::create($attrs);
        $this->events->add($transaction, 'created', 'Transaction draft created.', ['type'=>$type->value], $employee);
        $this->audit->log('transaction.created', $transaction, null, $transaction->only(['reference','type','status']), [], $employee);
        return $transaction;
    }

    public function recalculate(Transaction $transaction): Transaction
    {
        $transaction = $transaction->fresh(['agent','brand','payments','confirmations']);
        if (in_array($transaction->status, [TransactionStatus::Rejected,TransactionStatus::Completed,TransactionStatus::Cancelled], true)) return $transaction;

        if ($transaction->type->requiresAgentScreenshot() && !$transaction->agent_id) {
            $transaction->update(['status'=>TransactionStatus::Processing]);
            return $transaction->fresh();
        }

        if ($transaction->type->requiresBankEvidence()) return $this->recalculateBankTransaction($transaction);

        if ($transaction->type === TransactionType::Credit) {
            $outstanding = $this->credits->outstanding($transaction->agent);
            if (!$transaction->agent->credit_enabled) return $this->reject($transaction, 'credit_disabled', 'Credit is disabled for this agent.');
            if ($transaction->agent->credit_limit !== null && ($outstanding + (float) $transaction->amount) > (float) $transaction->agent->credit_limit) {
                return $this->reject($transaction, 'credit_limit_exceeded', 'Additional credit would exceed the configured agent credit limit.');
            }
            $transaction->update(['outstanding_credit_at_time'=>$outstanding,'status'=>TransactionStatus::ReadyForReview,'risk_level'=>RiskLevel::Normal]);
            return $transaction->fresh();
        }

        if ($transaction->type === TransactionType::Commission) {
            if (!$transaction->agent->commission_enabled) return $this->reject($transaction, 'commission_disabled', 'Commission Deposit is not enabled for this agent.');
            $count = Transaction::where('agent_id',$transaction->agent_id)->where('type',TransactionType::Commission->value)
                ->where('status',TransactionStatus::Completed->value)->whereBetween('completed_at',[now()->startOfMonth(),now()->endOfMonth()])->count();
            if ($count >= $transaction->agent->commission_monthly_limit) {
                return $this->reject($transaction, 'commission_monthly_limit', 'This agent has reached the monthly commission-deposit limit.');
            }
            $transaction->update(['status'=>TransactionStatus::ReadyForReview,'risk_level'=>RiskLevel::Normal]);
            return $transaction->fresh();
        }

        if ($transaction->type === TransactionType::Withdrawal) {
            $transaction->update(['status'=>filled($transaction->reason) ? TransactionStatus::ReadyForReview : TransactionStatus::Processing,'risk_level'=>RiskLevel::Normal]);
            return $transaction->fresh();
        }

        return $transaction;
    }

    private function recalculateBankTransaction(Transaction $transaction): Transaction
    {
        $payments = $transaction->payments;
        if ($payments->isEmpty()) {
            $transaction->update(['status'=>TransactionStatus::Processing,'valid_payment_total'=>0,'bank_payment_total'=>0,'difference'=>0]);
            return $transaction->fresh();
        }

        if ($payments->contains(fn ($p) => $p->internal_status === PaymentValidationStatus::Review)) {
            $transaction->update(['status'=>TransactionStatus::NeedsClearerScreenshot,'review_reason'=>'A bank payment cannot be uniquely verified from the screenshot.']);
            return $transaction->fresh();
        }

        $valid = $payments->filter(fn ($p) => $p->internal_status === PaymentValidationStatus::Valid);
        $rejectedCount = $payments->filter(fn ($p) => $p->internal_status === PaymentValidationStatus::Rejected)->count();
        $bankTotal = round((float) $payments->sum('amount'), 2);
        $validTotal = round((float) $valid->sum('amount'), 2);
        $risk = $valid->pluck('risk_level')->filter()->sortByDesc(fn ($r) => $r->weight())->first() ?? RiskLevel::Normal;

        $externalStatuses = $valid->pluck('external_status');
        $aggregateExternal = match (true) {
            $externalStatuses->contains(ExternalVerificationStatus::Failed) => ExternalVerificationStatus::Failed,
            $externalStatuses->contains(ExternalVerificationStatus::Unavailable) => ExternalVerificationStatus::Unavailable,
            $externalStatuses->contains(ExternalVerificationStatus::Passed) => ExternalVerificationStatus::Passed,
            $externalStatuses->contains(ExternalVerificationStatus::Disabled) => ExternalVerificationStatus::Disabled,
            default => ExternalVerificationStatus::NotRequired,
        };

        $target = $transaction->type === TransactionType::CreditRepayment ? $validTotal : (float) $transaction->amount;
        if ($transaction->type === TransactionType::CreditRepayment) $transaction->amount = $validTotal;
        $difference = round($validTotal - $target, 2);

        $transaction->fill([
            'bank_payment_total'=>$bankTotal,'valid_payment_total'=>$validTotal,'difference'=>$difference,
            'risk_level'=>$risk,'external_verification_status'=>$aggregateExternal,
        ])->save();

        $hasAdminExternalOverride = $transaction->confirmations->contains(fn ($c) => $c->kind === 'admin_external_override');
        $externalNeedsReview = !$hasAdminExternalOverride && match ($aggregateExternal) {
            ExternalVerificationStatus::Failed => $this->settings->get('check_et.failure_mode', config('services.check_et.failure_mode', 'review')) === 'review',
            ExternalVerificationStatus::Unavailable => $this->settings->get('check_et.outage_mode', config('services.check_et.outage_mode', 'review')) === 'review',
            default => false,
        };
        if ($externalNeedsReview) {
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>'Secondary Check.et verification requires Admin review.']);
            return $transaction->fresh();
        }

        if ($transaction->type === TransactionType::CreditRepayment) {
            $outstanding = $this->credits->outstanding($transaction->agent);
            $transaction->update(['outstanding_credit_at_time'=>$outstanding]);
            if ($validTotal <= 0) {
                $transaction->update(['status'=>TransactionStatus::Processing]);
                return $transaction->fresh();
            }
            if ($validTotal > $outstanding + 0.009) {
                $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>'Credit repayment exceeds current outstanding credit.']);
                return $transaction->fresh();
            }
            $transaction->update([
                'status'=>TransactionStatus::ReadyForReview,
                'difference'=>0,
                'review_reason'=>$rejectedCount > 0 ? "{$rejectedCount} rejected payment screenshot(s) were excluded from the valid total." : null,
            ]);
            return $transaction->fresh();
        }

        if ($validTotal < (float) $transaction->amount - 0.009) {
            $transaction->update([
                'status'=>TransactionStatus::Processing,
                'review_reason'=>$rejectedCount > 0
                    ? "{$rejectedCount} rejected payment screenshot(s) were excluded. Add valid payment evidence until the valid total matches the agent-system amount."
                    : null,
            ]);
            return $transaction->fresh();
        }
        if ($validTotal > (float) $transaction->amount + 0.009) {
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>'Combined valid bank payments exceed the agent-system top-up amount.']);
            return $transaction->fresh();
        }

        $transaction->update([
            'status'=>TransactionStatus::ReadyForReview,
            'difference'=>0,
            'review_reason'=>$rejectedCount > 0 ? "{$rejectedCount} rejected payment screenshot(s) were excluded from the valid total." : null,
        ]);
        return $transaction->fresh();
    }

    public function reject(Transaction $transaction, string $code, string $reason): Transaction
    {
        if ($transaction->status === TransactionStatus::Completed) throw new RuntimeException('Completed transactions cannot be rejected.');
        $before = $transaction->only(['status','rejection_code','rejection_reason']);
        $transaction->update(['status'=>TransactionStatus::Rejected,'rejection_code'=>$code,'rejection_reason'=>$reason,'rejected_at'=>now()]);
        $this->events->add($transaction, 'rejected', $reason, ['code'=>$code]);
        $this->audit->log('transaction.rejected', $transaction, $before, $transaction->only(['status','rejection_code','rejection_reason']));
        return $transaction->fresh();
    }

    public function confirmCritical(Transaction $transaction, User $employee): void
    {
        foreach (['critical_time_confirmation_1','critical_time_confirmation_2'] as $kind) {
            TransactionConfirmation::firstOrCreate(
                ['transaction_id'=>$transaction->id,'user_id'=>$employee->id,'kind'=>$kind],
                ['metadata'=>['risk'=>$transaction->risk_level?->value],'confirmed_at'=>now()]
            );
        }
        $this->events->add($transaction, 'critical_time_confirmed', 'Employee completed both critical time-difference confirmations.', [], $employee);
    }

    public function addAdminExternalOverride(Transaction $transaction, User $admin, string $note): Transaction
    {
        TransactionConfirmation::updateOrCreate(
            ['transaction_id'=>$transaction->id,'user_id'=>$admin->id,'kind'=>'admin_external_override'],
            ['metadata'=>['note'=>$note],'confirmed_at'=>now()]
        );
        $this->events->add($transaction, 'admin_external_override', 'Admin approved transaction to proceed using internal verification despite secondary verification status.', ['note'=>$note], $admin);
        $this->audit->log('transaction.external_override', $transaction, null, ['note'=>$note], [], $admin);
        return $this->recalculate($transaction);
    }

    public function finalize(Transaction $transaction, User $employee): Transaction
    {
        return DB::transaction(function () use ($transaction, $employee) {
            /** @var Transaction $locked */
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $locked = $this->recalculate($locked);
            if ($locked->status !== TransactionStatus::ReadyForReview) throw new RuntimeException('Transaction is not ready to be completed.');

            if ($locked->risk_level === RiskLevel::Critical) {
                $count = TransactionConfirmation::where('transaction_id',$locked->id)->where('user_id',$employee->id)
                    ->whereIn('kind',['critical_time_confirmation_1','critical_time_confirmation_2'])->count();
                if ($count < 2) throw new RuntimeException('Critical time-difference transaction requires both employee confirmations.');
            }

            if ($locked->type === TransactionType::Commission) {
                $agent = Agent::whereKey($locked->agent_id)->lockForUpdate()->firstOrFail();
                $count = Transaction::where('agent_id',$agent->id)->where('type',TransactionType::Commission->value)
                    ->where('status',TransactionStatus::Completed->value)->whereBetween('completed_at',[now()->startOfMonth(),now()->endOfMonth()])->count();
                if (!$agent->commission_enabled || $count >= $agent->commission_monthly_limit) throw new RuntimeException('Commission eligibility changed before completion.');
            }

            if ($locked->type === TransactionType::Credit) {
                $agent = Agent::whereKey($locked->agent_id)->lockForUpdate()->firstOrFail();
                $outstanding = $this->credits->outstanding($agent);
                if ($agent->credit_limit !== null && $outstanding + (float)$locked->amount > (float)$agent->credit_limit) throw new RuntimeException('Credit limit changed before completion.');
                $this->credits->issue($agent, $locked, (float) $locked->amount);
            }
            if ($locked->type === TransactionType::CreditRepayment) {
                $agent = Agent::whereKey($locked->agent_id)->lockForUpdate()->firstOrFail();
                $outstanding = $this->credits->outstanding($agent);
                if ((float)$locked->amount > $outstanding + 0.009) throw new RuntimeException('Repayment exceeds current outstanding credit.');
                $this->credits->repay($agent, $locked, (float) $locked->amount);
            }

            $before = $locked->only(['status','completed_at']);
            $locked->update(['status'=>TransactionStatus::Completed,'completed_at'=>now(),'review_reason'=>null]);
            $this->events->add($locked, 'completed', 'Transaction completed and locked into audit history.', [], $employee);
            $this->audit->log('transaction.completed', $locked, $before, $locked->only(['status','completed_at']), [], $employee);
            return $locked->fresh();
        }, 3);
    }
}
