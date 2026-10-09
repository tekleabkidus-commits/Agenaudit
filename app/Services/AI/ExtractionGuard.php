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
        $qualityChecks = $this->settings->bool('ai.quality_check_enabled', true);

        if ($qualityChecks) {
            $quality = (float) data_get($payload, 'quality.score', 0);
            $critical = (float) data_get($payload, 'quality.critical_confidence', 0);
            $minQuality = $this->settings->float('ai.min_quality_score', (float) config('services.ai.min_quality_score', 0.80));
            $minCritical = $this->settings->float('ai.min_critical_confidence', (float) config('services.ai.min_critical_confidence', 0.75));

            if ($quality < $minQuality) {
                throw new ClearerScreenshotRequiredException('low_quality', 'Screenshot quality is too low. Please upload a clearer screenshot.');
            }

            if ($critical < $minCritical) {
                throw new ClearerScreenshotRequiredException('low_confidence', 'Critical information cannot be read reliably. Please upload a clearer screenshot.');
            }
        }

        $required = $kind === EvidenceKind::AgentSystem
            ? ['agent_id','agent_username','amount','transaction_at']
            : ['to_bank','receiver_account','receiver_name','amount','transaction_id','transaction_at'];

        if ($kind === EvidenceKind::BankPayment && blank(data_get($payload,'from_bank'))) {
            throw new ClearerScreenshotRequiredException('missing_from_bank', 'Sender institution cannot be identified. Upload the official invoice or another screenshot that confirms the issuer.');
        }

        if ($kind === EvidenceKind::BankPayment && data_get($payload,'_receipt_intelligence.amount_needs_review',false)) {
            throw new \App\Exceptions\ReviewRequiredException('transfer_amount_uncertain', 'The screenshot does not establish whether the displayed amount includes transfer fees. Admin review or official invoice is required.');
        }

        foreach ($required as $field) {
            $value = data_get($payload, $field);
            if ($value === null || $value === '') {
                throw new ClearerScreenshotRequiredException('missing_'.$field, "Required field {$field} is not readable. Please upload a clearer screenshot.");
            }
        }
    }

    public function requiresEmployeeConfirmation(array $payload): bool
    {
        if (!$this->settings->bool('ai.quality_check_enabled', true)) {
            return false;
        }

        $critical = (float) data_get($payload, 'quality.critical_confidence', 0);
        $autoAccept = $this->settings->float('ai.auto_accept_confidence', 0.90);

        return $critical < $autoAccept;
    }
}
