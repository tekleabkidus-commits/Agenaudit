<?php

namespace App\Services\Banking;

use App\Models\ReceivingAccount;

readonly class ReceivingAccountMatch
{
    public function __construct(
        public string $status,
        public ?ReceivingAccount $account = null,
        public ?string $method = null,
        public float $confidence = 0,
        public ?string $reason = null,
    ) {}
}
