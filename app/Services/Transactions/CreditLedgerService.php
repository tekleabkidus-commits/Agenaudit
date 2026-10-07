<?php

namespace App\Services\Transactions;

use App\Enums\CreditEntryType;
use App\Models\Agent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditRecord;
use App\Models\CreditRepaymentAllocation;
use App\Models\Transaction;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreditLedgerService
{
    public function __construct(private SettingsService $settings) {}

    public function outstanding(Agent|int $agent): float
    {
        $agentId = $agent instanceof Agent ? $agent->id : $agent;

        $row = CreditLedgerEntry::query()
            ->where('agent_id',$agentId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",
                [CreditEntryType::Issue->value,CreditEntryType::Repayment->value]
            )
            ->first();

        return round((float)($row?->outstanding ?? 0), 2);
    }

    /** @param iterable<int> $agentIds @return array<int,float> */
    public function outstandingMap(iterable $agentIds): array
    {
        $ids = collect($agentIds)->map(fn($id)=>(int)$id)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return CreditLedgerEntry::query()
            ->whereIn('agent_id',$ids)
            ->selectRaw(
                "agent_id, COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",
                [CreditEntryType::Issue->value,CreditEntryType::Repayment->value]
            )
            ->groupBy('agent_id')
            ->get()
            ->mapWithKeys(fn($r)=>[(int)$r->agent_id=>round((float)$r->outstanding,2)])
            ->all();
    }

    /** @return Collection<int,array{agent:Agent,outstanding:float}> */
    public function agentsWithOutstanding(): Collection
    {
        $balances = CreditLedgerEntry::query()
            ->selectRaw(
                "agent_id, COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",
                [CreditEntryType::Issue->value,CreditEntryType::Repayment->value]
            )
            ->groupBy('agent_id')
            ->havingRaw(
                "COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) > 0",
                [CreditEntryType::Issue->value,CreditEntryType::Repayment->value]
            )
            ->get()
            ->keyBy('agent_id');

        if ($balances->isEmpty()) return collect();

        return Agent::with('brand')
            ->whereIn('id',$balances->keys())
            ->where('is_active',true)
            ->orderBy('agent_id')
            ->get()
            ->map(fn(Agent $a)=>[
                'agent'=>$a,
                'outstanding'=>round((float)$balances[$a->id]->outstanding,2),
            ]);
    }

    public function issue(Agent $agent, Transaction $transaction, float $amount): CreditLedgerEntry
    {
        $before = $this->outstanding($agent);

        $entry = CreditLedgerEntry::create([
            'agent_id'=>$agent->id,
            'transaction_id'=>$transaction->id,
            'entry_type'=>CreditEntryType::Issue,
            'amount'=>$amount,
            'balance_before'=>$before,
            'balance_after'=>$before+$amount,
            'occurred_at'=>now(),
        ]);

        $dueDays = $agent->credit_due_days
            ?? $this->settings->int('credit.default_due_days', 7);

        CreditRecord::firstOrCreate(
            ['issue_transaction_id'=>$transaction->id],
            [
                'agent_id'=>$agent->id,
                'original_amount'=>$amount,
                'repaid_amount'=>0,
                'outstanding_amount'=>$amount,
                'status'=>'unpaid',
                'issued_at'=>$transaction->completed_at ?? now(),
                'due_at'=>$dueDays > 0 ? now()->addDays($dueDays) : null,
            ]
        );

        return $entry;
    }

    public function repay(Agent $agent, Transaction $transaction, float $amount): CreditLedgerEntry
    {
        return DB::transaction(function () use ($agent,$transaction,$amount) {
            $before = $this->outstanding($agent);

            $entry = CreditLedgerEntry::create([
                'agent_id'=>$agent->id,
                'transaction_id'=>$transaction->id,
                'entry_type'=>CreditEntryType::Repayment,
                'amount'=>$amount,
                'balance_before'=>$before,
                'balance_after'=>max(0,$before-$amount),
                'occurred_at'=>now(),
            ]);

            $remaining = round($amount, 2);

            $records = CreditRecord::query()
                ->where('agent_id',$agent->id)
                ->whereIn('status',['unpaid','partial'])
                ->where('outstanding_amount','>',0)
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderBy('issued_at')
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                if ($remaining <= 0) break;

                $apply = min($remaining, (float)$record->outstanding_amount);
                if ($apply <= 0) continue;

                CreditRepaymentAllocation::updateOrCreate(
                    [
                        'credit_record_id'=>$record->id,
                        'repayment_transaction_id'=>$transaction->id,
                    ],
                    [
                        'amount'=>$apply,
                        'allocated_at'=>now(),
                    ]
                );

                $repaid = round((float)$record->repaid_amount + $apply, 2);
                $outstanding = max(0, round((float)$record->original_amount - $repaid, 2));
                $status = $outstanding <= 0.009 ? 'paid' : 'partial';

                $record->update([
                    'repaid_amount'=>$repaid,
                    'outstanding_amount'=>$outstanding,
                    'status'=>$status,
                    'paid_at'=>$status === 'paid' ? now() : null,
                ]);

                $remaining = round($remaining - $apply, 2);
            }

            // Historical installations may have ledger credit without a CreditRecord.
            // The ledger remains authoritative, so we never lose the repayment.
            return $entry;
        }, 3);
    }

    public function agingStatus(CreditRecord $record): string
    {
        if ($record->status === 'paid') return 'paid';
        if (!$record->due_at || now()->lte($record->due_at)) return 'current';

        $days = $record->due_at->diffInDays(now());

        if ($days >= $this->settings->int('credit.critical_overdue_days', 7)) return 'critical';
        if ($days >= $this->settings->int('credit.serious_overdue_days', 3)) return 'serious';
        if ($days >= $this->settings->int('credit.warning_overdue_days', 1)) return 'warning';

        return 'current';
    }

    public function openRecords(?int $brandId = null): Collection
    {
        return CreditRecord::query()
            ->with(['agent.brand','issueTransaction'])
            ->whereIn('status',['unpaid','partial'])
            ->where('outstanding_amount','>',0)
            ->when($brandId, fn($q)=>$q->whereHas('agent', fn($a)=>$a->where('brand_id',$brandId)))
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('issued_at')
            ->get()
            ->each(fn(CreditRecord $record) => $record->setAttribute('aging_status', $this->agingStatus($record)));
    }
}
