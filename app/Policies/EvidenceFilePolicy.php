<?php

namespace App\Policies;

use App\Models\EvidenceFile;
use App\Models\User;

class EvidenceFilePolicy
{
    public function view(User $user, EvidenceFile $evidence): bool
    {
        if ($user->isAdmin()) return true;

        $transaction = $evidence->transaction;

        return $transaction->employee_id === $user->id
            && (!$transaction->brand_id || $user->canAccessBrand($transaction->brand_id));
    }
}
