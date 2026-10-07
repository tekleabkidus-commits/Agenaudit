<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool { return $user->isAdmin() || $transaction->employee_id === $user->id; }
    public function update(User $user, Transaction $transaction): bool { return $user->isAdmin() || ($transaction->employee_id === $user->id && !in_array($transaction->status->value, ['completed','rejected','cancelled'], true)); }
}
