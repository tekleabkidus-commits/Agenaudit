<?php

namespace App\Services\Transactions;

use App\Enums\TransactionType;
use App\Exceptions\HardRejectException;
use App\Exceptions\ReviewRequiredException;
use App\Models\Agent;
use App\Models\Transaction;
use App\Support\Normalizer;
use Carbon\CarbonImmutable;
use Throwable;

class AgentIdentityService
{
    public function __construct(private CreditLedgerService $credits, private TransactionEventLogger $events) {}

    public function apply(Transaction $transaction, array $extracted): Agent
    {
        $agentId = Normalizer::identifier((string) data_get($extracted, 'agent_id'));
        $username = Normalizer::identifier((string) data_get($extracted, 'agent_username'));
        if (!$agentId || !$username) throw new ReviewRequiredException('agent_identity_incomplete', 'Agent ID or username is missing.');

        $byId = Agent::with('brand')->where('agent_id_normalized', $agentId)->first();
        $byUsername = Agent::with('brand')->where('username_normalized', $username)->first();
        if (!$byId || !$byUsername) throw new ReviewRequiredException('agent_not_registered', 'Detected agent is not registered in the master agent list.');
        if ($byId->id !== $byUsername->id) throw new ReviewRequiredException('agent_identity_conflict', 'Agent ID and username resolve to different agents.');
        if (!$byId->is_active || !$byId->brand->is_active) throw new HardRejectException('inactive_agent', 'The detected agent or brand is inactive.');

        $employee = $transaction->employee()->first();
        if (!$employee || !$employee->canAccessBrand($byId->brand_id)) {
            throw new HardRejectException('employee_brand_not_allowed', 'This employee is not assigned to the detected agent brand.');
        }

        $brandHint = data_get($extracted, 'brand_hint');
        if ($brandHint && Normalizer::name($brandHint) !== Normalizer::name($byId->brand->name)) {
            throw new ReviewRequiredException('brand_conflict', 'AI brand hint conflicts with the agent master record.');
        }

        $amount = round((float) data_get($extracted, 'amount'), 2);
        if ($amount <= 0) throw new ReviewRequiredException('invalid_agent_amount', 'Agent-system amount must be greater than zero.');
        try { $at = CarbonImmutable::parse((string) data_get($extracted, 'transaction_at')); }
        catch (Throwable) { throw new ReviewRequiredException('invalid_agent_time', 'Agent-system transaction time could not be parsed.'); }

        $outstanding = $this->credits->outstanding($byId);
        $transaction->update([
            'agent_id'=>$byId->id,
            'brand_id'=>$byId->brand_id,
            'amount'=>$amount,
            'agent_system_at'=>$at,
            'outstanding_credit_at_time'=>$outstanding,
        ]);

        if ($transaction->type === TransactionType::Credit && !$byId->credit_enabled) {
            throw new HardRejectException('credit_disabled', 'Credit is disabled for this agent.');
        }
        if ($transaction->type === TransactionType::Commission && !$byId->commission_enabled) {
            throw new HardRejectException('commission_disabled', 'Commission Deposit is not enabled for this agent.');
        }

        $this->events->add($transaction, 'agent_identified', "Agent {$byId->agent_id} identified automatically; brand {$byId->brand->name} derived from master data.", [
            'agent_id'=>$byId->agent_id,'agent_username'=>$byId->username,'brand'=>$byId->brand->name,'outstanding_credit'=>$outstanding,
        ]);
        return $byId;
    }
}
