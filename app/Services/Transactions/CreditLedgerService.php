<?php
namespace App\Services\Transactions;

use App\Enums\CreditEntryType;
use App\Models\Agent;
use App\Models\CreditLedgerEntry;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class CreditLedgerService
{
    public function outstanding(Agent|int $agent): float
    {
        $agentId=$agent instanceof Agent?$agent->id:$agent;
        $row=CreditLedgerEntry::query()->where('agent_id',$agentId)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",[CreditEntryType::Issue->value,CreditEntryType::Repayment->value])->first();
        return round((float)($row?->outstanding??0),2);
    }

    /** @param iterable<int> $agentIds @return array<int,float> */
    public function outstandingMap(iterable $agentIds): array
    {
        $ids=collect($agentIds)->map(fn($id)=>(int)$id)->filter()->unique()->values();
        if($ids->isEmpty()) return [];
        return CreditLedgerEntry::query()->whereIn('agent_id',$ids)
            ->selectRaw("agent_id, COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",[CreditEntryType::Issue->value,CreditEntryType::Repayment->value])
            ->groupBy('agent_id')->get()->mapWithKeys(fn($r)=>[(int)$r->agent_id=>round((float)$r->outstanding,2)])->all();
    }

    /** @return Collection<int,array{agent:Agent,outstanding:float}> */
    public function agentsWithOutstanding(): Collection
    {
        $balances=CreditLedgerEntry::query()
            ->selectRaw("agent_id, COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) AS outstanding",[CreditEntryType::Issue->value,CreditEntryType::Repayment->value])
            ->groupBy('agent_id')->havingRaw("COALESCE(SUM(CASE WHEN entry_type = ? THEN amount WHEN entry_type = ? THEN -amount ELSE amount END), 0) > 0",[CreditEntryType::Issue->value,CreditEntryType::Repayment->value])
            ->get()->keyBy('agent_id');
        if($balances->isEmpty()) return collect();
        return Agent::with('brand')->whereIn('id',$balances->keys())->where('is_active',true)->orderBy('agent_id')->get()->map(fn(Agent $a)=>['agent'=>$a,'outstanding'=>round((float)$balances[$a->id]->outstanding,2)]);
    }

    public function issue(Agent $agent, Transaction $transaction, float $amount): CreditLedgerEntry
    {
        $before=$this->outstanding($agent);
        return CreditLedgerEntry::create(['agent_id'=>$agent->id,'transaction_id'=>$transaction->id,'entry_type'=>CreditEntryType::Issue,'amount'=>$amount,'balance_before'=>$before,'balance_after'=>$before+$amount,'occurred_at'=>now()]);
    }
    public function repay(Agent $agent, Transaction $transaction, float $amount): CreditLedgerEntry
    {
        $before=$this->outstanding($agent);
        return CreditLedgerEntry::create(['agent_id'=>$agent->id,'transaction_id'=>$transaction->id,'entry_type'=>CreditEntryType::Repayment,'amount'=>$amount,'balance_before'=>$before,'balance_after'=>max(0,$before-$amount),'occurred_at'=>now()]);
    }
}
