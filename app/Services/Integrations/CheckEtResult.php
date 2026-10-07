<?php

namespace App\Services\Integrations;

readonly class CheckEtResult
{
    public function __construct(
        public string $status,
        public array $payload,
        public array $requestKeys,
        public ?string $reason = null,
    ) {}
}
