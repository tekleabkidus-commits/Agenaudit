<?php

namespace App\Enums;

enum RiskLevel: string
{
    case Normal = 'normal';
    case Warning = 'warning';
    case Serious = 'serious';
    case Alarming = 'alarming';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Warning => 1,
            self::Serious => 2,
            self::Alarming => 3,
            self::Critical => 4,
        };
    }
}
