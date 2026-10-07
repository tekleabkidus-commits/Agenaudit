<?php

namespace App\Services\AI;

use App\Enums\EvidenceKind;
use App\Exceptions\ClearerScreenshotRequiredException;
use App\Services\SettingsService;

class ExtractionGuard
{
    public function __construct(private SettingsService $settings) {}

    public function assertUsable(array $payload, EvidenceKind $kind): void
    {
        $quality = (float) data_get($payload, 'quality.score', 0);
        $critical = (float) data_get($payload, 'quality.critical_confidence', 0);
        $minQuality = $this->settings->float('ai.min_quality_score', (float) config('services.ai.min_quality_score', 0.80));
        $minCritical = $this->settings->float('ai.min_critical_confidence', (float) config('services.ai.min_critical_confidence', 0.85));

        if ($quality < $minQuality) {
            throw new ClearerScreenshotRequiredException('low_quality', 'Screenshot quality is too low. Please upload a clearer screenshot.');
        }
        if ($critical < $minCritical) {
            throw new ClearerScreenshotRequiredException('low_confidence', 'Critical information cannot be read reliably. Please upload a clearer screenshot.');
        }

        $required = $kind === EvidenceKind::AgentSystem
            ? ['agent_id','agent_username','amount','transaction_at']
            : ['from_bank','to_bank','receiver_account','receiver_name','amount','transaction_id','transaction_at'];

        foreach ($required as $field) {
            $value = data_get($payload, $field);
            if ($value === null || $value === '') {
                throw new ClearerScreenshotRequiredException('missing_'.$field, "Required field {$field} is not readable. Please upload a clearer screenshot.");
            }
        }
    }
}
