<?php

namespace App\Enums;

enum EvidenceKind: string
{
    case AgentSystem = 'agent_system';
    case BankPayment = 'bank_payment';
}
