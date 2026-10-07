<?php

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;

class TransactionEventLogger
{
    public function add(Transaction $transaction, string $type, string $message, array $data = [], ?User $actor = null): TransactionEvent
    {
        return TransactionEvent::create([
            'transaction_id'=>$transaction->id,
            'actor_id'=>$actor?->id ?? auth()->id(),
            'event_type'=>$type,
            'message'=>$message,
            'data'=>$data ?: null,
            'created_at'=>now(),
        ]);
    }
}
