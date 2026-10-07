<?php

namespace App\Services\Banking;

use App\Enums\RiskLevel;
use App\Services\SettingsService;
use Carbon\CarbonInterface;

class TimeRiskService
{
    public function __construct(private SettingsService $settings) {}

    public function evaluate(CarbonInterface $bankAt, CarbonInterface $topupAt): array
    {
        $minutes = abs($bankAt->diffInMinutes($topupAt, false));
        $risk = match (true) {
            $minutes > $this->settings->int('validation.critical_minutes', 720) => RiskLevel::Critical,
            $minutes > $this->settings->int('validation.alarming_minutes', 360) => RiskLevel::Alarming,
            $minutes > $this->settings->int('validation.serious_minutes', 180) => RiskLevel::Serious,
            $minutes > $this->settings->int('validation.warning_minutes', 60) => RiskLevel::Warning,
            default => RiskLevel::Normal,
        };
        return [$risk, $minutes];
    }
}
