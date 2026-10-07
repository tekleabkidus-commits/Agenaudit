<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->isAdmin()) return true;
        if ($transaction->employee_id !== $user->id) return false;
        return !$transaction->brand_id || $user->canAccessBrand($transaction->brand_id);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        if ($user->isAdmin()) return true;
        if ($transaction->employee_id !== $user->id) return false;
        if ($transaction->brand_id && !$user->canAccessBrand($transaction->brand_id)) return false;

        return !in_array($transaction->status->value, ['completed','rejected','cancelled'], true);
    }
}
