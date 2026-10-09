<?php

namespace App\Services\Transactions;

use App\Jobs\ProcessEvidenceJob;

/**
 * Read agent screenshots in the upload request when fast mode is enabled.
 * This removes all database queue wait for the initial agent identification.
 *
 * Bank receipt checks remain queued because a batch can contain 12 external
 * Gemini + Check.et requests, potentially exceeding PHP request time limits.
 */
final class FastEvidenceDispatcher
{
    public function agent(int $evidenceId): void
    {
        if (!config('agent_audit.evidence.fast_agent_extraction', false)) {
            ProcessEvidenceJob::dispatch($evidenceId);
            return;
        }

        $originalTimeout = (int) config('services.ai.timeout', 45);
        $inlineTimeout = (int) config('agent_audit.evidence.fast_agent_timeout_seconds', 18);

        try {
            // Bound just the AI call during web-request processing. If it times
            // out, EvidenceProcessor records a visible failed/Admin-review
            // state rather than leaving a permanently 'queued' screenshot.
            config()->set('services.ai.timeout', max(5, min($originalTimeout, $inlineTimeout)));
            ProcessEvidenceJob::dispatchSync($evidenceId);
        } finally {
            config()->set('services.ai.timeout', $originalTimeout);
        }
    }
}
