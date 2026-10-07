<?php

namespace App\Policies;

use App\Models\EvidenceFile;
use App\Models\User;

class EvidenceFilePolicy
{
    public function view(User $user, EvidenceFile $evidence): bool { return $user->isAdmin() || $evidence->transaction->employee_id === $user->id; }
}
