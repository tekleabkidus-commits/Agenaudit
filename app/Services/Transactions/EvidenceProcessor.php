<?php

namespace App\Services\Transactions;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\ClearerScreenshotRequiredException;
use App\Exceptions\HardRejectException;
use App\Exceptions\ReviewRequiredException;
use App\Models\EvidenceFile;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Services\AI\ExtractionGuard;
use App\Services\AI\VisionExtractorInterface;
use App\Services\Banking\PaymentVerificationService;
use App\Services\Banking\ReceiptIntelligence;
use App\Services\SettingsService;
use RuntimeException;
use Throwable;

class EvidenceProcessor
{
    public function __construct(
        private VisionExtractorInterface $vision,
        private ExtractionGuard $guard,
        private AgentIdentityService $agents,
        private PaymentVerificationService $payments,
        private ReceiptIntelligence $receipts,
        private TransactionWorkflowService $workflow,
        private TransactionEventLogger $events,
        private SettingsService $settings,
    ) {}

    public function process(EvidenceFile $evidence): void
    {
        $transaction = $evidence->transaction;
        $group = $this->activeGroup($evidence);
        if ($evidence->kind === EvidenceKind::AgentSystem && $group->first()?->id !== $evidence->id) {
            // Only the first agent proof queues extraction for the whole group.
            return;
        }

        if ($this->isFinalized($transaction)) {
            // Delayed jobs must never change financial evidence after closure.
            foreach ($group as $file) {
                $file->update([
                    'status'=>EvidenceStatus::Superseded,
                    'failure_reason'=>'Transaction was finalized before evidence processing.',
                ]);
            }
            return;
        }


        // Claim the queue item atomically. A fast web request and an old
        // queued worker can race after a retry, but only one may call Gemini
        // or apply the resulting financial change.
        $claimed = EvidenceFile::query()
            ->whereKey($evidence->id)
            ->where('status', EvidenceStatus::Queued->value)
            ->update(['status'=>EvidenceStatus::Processing->value]);
        if ($claimed !== 1) {
            return;
        }
        $evidence->refresh();

        if (!$this->aiEnabledFor($evidence->kind)) {
            foreach ($group as $file) {
                $file->update([
                    'status'=>EvidenceStatus::PendingAdminExtraction,
                    'failure_reason'=>'Automatic extraction is disabled for this evidence type.',
                ]);
            }
            $transaction->update([
                'status'=>TransactionStatus::PendingAdminExtraction,
                'review_reason'=>'Automatic extraction is disabled; Admin extraction is required.',
            ]);
            $this->events->add($transaction, 'ai_disabled', 'Evidence queued for Admin extraction because this AI module is disabled.');
            return;
        }

        foreach ($group as $file) {
            $file->update(['status'=>EvidenceStatus::Processing,'failure_reason'=>null]);
        }

        try {
            // Supplementary agent proofs are one single financial operation.
            // Bank receipts are always extracted separately.
            $payload = $group->count() > 1
                ? $this->vision->extractMany($group->all())
                : $this->vision->extract($evidence);

            if ($evidence->kind === EvidenceKind::BankPayment) {
                $payload = $this->receipts->normalize($payload);
            }

            if (
                $evidence->kind === EvidenceKind::AgentSystem
                && !$this->settings->bool('ai.brand_detection_enabled', true)
            ) {
                $payload['brand_hint'] = null;
            }

            foreach ($group as $file) {
                $file->update([
                    'raw_ai_response'=>$payload,
                    'quality_score'=>(float) data_get($payload,'quality.score',0),
                    'critical_confidence'=>(float) data_get($payload,'quality.critical_confidence',0),
                ]);
            }

            $this->guard->assertUsable($payload, $evidence->kind);

            if ($this->guard->requiresEmployeeConfirmation($payload)) {
                foreach ($group as $file) {
                    $file->update([
                        'status'=>$file->id === $evidence->id ? EvidenceStatus::PendingEmployeeConfirmation : EvidenceStatus::Extracted,
                        'extracted'=>$payload,
                        'failure_reason'=>null,
                    ]);
                }
                $transaction->update([
                    'status'=>TransactionStatus::PendingEmployeeConfirmation,
                    'review_reason'=>'AI confidence is medium. Employee confirmation is required before the extracted values are used.',
                ]);
                $this->events->add($transaction, 'employee_confirmation_required', 'AI extraction has medium confidence and is waiting for Employee confirmation.', [
                    'evidence_id'=>$evidence->id,
                    'confidence'=>$evidence->critical_confidence,
                ]);
                return;
            }

            foreach ($group as $file) {
                $file->update([
                    'status'=>EvidenceStatus::Extracted,
                    'extracted'=>$payload,
                    'failure_reason'=>null,
                ]);
            }

            $this->applyWithHandling($evidence, $payload);
        } catch (ClearerScreenshotRequiredException $e) {
            $this->markNeedsReupload($evidence, $e->getMessage(), $e->reasonCode);
        } catch (HardRejectException $e) {
            foreach ($group as $file) $file->update(['failure_reason'=>$e->getMessage()]);
            $this->workflow->reject($transaction, $e->codeName, $e->getMessage());
        } catch (ReviewRequiredException $e) {
            foreach ($group as $file) $file->update(['failure_reason'=>$e->getMessage()]);
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>$e->getMessage()]);
            $this->events->add($transaction, 'admin_review_required', $e->getMessage(), ['code'=>$e->reasonCode]);
        } catch (Throwable $e) {
            report($e);
            foreach ($group as $file) $file->update(['status'=>EvidenceStatus::Failed,'failure_reason'=>'Evidence processing failed.']);
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>'Evidence processor failed; Admin review required.']);
            $this->events->add($transaction, 'processing_failed', 'Evidence processing failed and was routed to Admin review.');
        }
    }

    public function confirmEmployeeExtraction(EvidenceFile $evidence, User $employee): void
    {
        if ($evidence->status !== EvidenceStatus::PendingEmployeeConfirmation) {
            throw new RuntimeException('This evidence is not waiting for Employee confirmation.');
        }

        if ($evidence->transaction->employee_id !== $employee->id) {
            throw new RuntimeException('You cannot confirm another employee’s evidence.');
        }

        $payload = $evidence->extracted;
        if (!$payload) {
            throw new RuntimeException('No extracted data is available to confirm.');
        }

        $evidence->update([
            'status'=>EvidenceStatus::Extracted,
            'employee_confirmed_by'=>$employee->id,
            'employee_confirmed_at'=>now(),
            'failure_reason'=>null,
        ]);

        $this->events->add($evidence->transaction, 'employee_extraction_confirmed', 'Employee confirmed the medium-confidence AI extraction.', [
            'evidence_id'=>$evidence->id,
            'confidence'=>$evidence->critical_confidence,
        ], $employee);

        $this->applyWithHandling($evidence->fresh(), $payload);
    }

    public function requestClearerScreenshot(EvidenceFile $evidence, User $employee): void
    {
        if ($evidence->status !== EvidenceStatus::PendingEmployeeConfirmation) {
            throw new RuntimeException('This evidence is not waiting for Employee confirmation.');
        }

        if ($evidence->transaction->employee_id !== $employee->id) {
            throw new RuntimeException('You cannot replace another employee’s evidence.');
        }

        $this->markNeedsReupload($evidence, 'Employee did not confirm the AI extraction and requested a clearer screenshot.', 'employee_requested_reupload', $employee);
    }

    public function applyManualExtraction(EvidenceFile $evidence, array $payload, User $admin): void
    {
        if ($this->isFinalized($evidence->transaction)) {
            throw new RuntimeException('Finalized transactions cannot receive evidence.');
        }

        if ($evidence->status !== EvidenceStatus::PendingAdminExtraction) {
            throw new RuntimeException('Manual extraction is only allowed when automatic extraction is disabled for this evidence.');
        }

        foreach ($this->activeGroup($evidence) as $file) {
            $file->update([
                'status'=>EvidenceStatus::Extracted,
                'extracted'=>$payload,
                'raw_ai_response'=>null,
                'quality_score'=>1,
                'critical_confidence'=>1,
                'failure_reason'=>null,
            ]);
        }

        $this->events->add($evidence->transaction, 'admin_manual_extraction', 'Admin supplied extraction while AI was disabled.', ['evidence_id'=>$evidence->id], $admin);
        $this->applyWithHandling($evidence, $payload);
    }

    public function reapplyExtracted(EvidenceFile $evidence, User $admin): void
    {
        if (!$evidence->extracted) throw new RuntimeException('This evidence has no extracted data to revalidate.');

        if (in_array($evidence->transaction->status, [TransactionStatus::Completed, TransactionStatus::Rejected, TransactionStatus::Cancelled], true)) {
            throw new RuntimeException('Finalized transactions cannot be revalidated.');
        }

        $this->events->add($evidence->transaction, 'admin_revalidation', 'Admin requested revalidation of previously extracted evidence.', ['evidence_id'=>$evidence->id], $admin);
        $this->applyWithHandling($evidence, $evidence->extracted);
    }

    private function applyWithHandling(EvidenceFile $evidence, array $payload): void
    {
        try {
            $transaction = $evidence->transaction->fresh(['brand','agent']);

            if ($this->isFinalized($transaction)) {
                $evidence->update([
                    'status'=>EvidenceStatus::Superseded,
                    'failure_reason'=>'Transaction was finalized before evidence could be applied.',
                ]);
                return;
            }

            if ($evidence->kind === EvidenceKind::AgentSystem) {
                $this->agents->apply($transaction, $payload);
            } else {
                $this->payments->apply($transaction, $evidence, $payload);
            }

            $this->workflow->recalculate($transaction->fresh());
        } catch (ClearerScreenshotRequiredException $e) {
            $this->markNeedsReupload($evidence, $e->getMessage(), $e->reasonCode);
        } catch (HardRejectException $e) {
            $evidence->update(['failure_reason'=>$e->getMessage()]);
            $this->workflow->reject($evidence->transaction, $e->codeName, $e->getMessage());
        } catch (ReviewRequiredException $e) {
            $evidence->update(['failure_reason'=>$e->getMessage()]);
            $evidence->transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>$e->getMessage()]);
            $this->events->add($evidence->transaction, 'admin_review_required', $e->getMessage(), ['code'=>$e->reasonCode]);
        }
    }

    private function markNeedsReupload(EvidenceFile $evidence, string $message, string $code, ?User $actor = null): void
    {
        foreach ($this->activeGroup($evidence) as $file) {
            $file->update(['status'=>EvidenceStatus::NeedsReupload,'failure_reason'=>$message]);
        }
        $evidence->transaction->update(['status'=>TransactionStatus::NeedsClearerScreenshot,'review_reason'=>$message]);
        $this->events->add($evidence->transaction, 'clearer_screenshot_required', $message, ['code'=>$code], $actor);
    }

    private function aiEnabledFor(EvidenceKind $kind): bool
    {
        // The Gemini free API can use submitted content to improve Google
        // services. Do not send private bank proof until an administrator
        // explicitly approves that provider's data handling.
        if (config('services.ai.driver') === 'gemini'
            && !config('services.ai.gemini_allow_sensitive_evidence', false)) {
            return false;
        }

        if (!$this->settings->bool('ai.enabled', (bool) config('services.ai.enabled'))) {
            return false;
        }

        return $kind === EvidenceKind::AgentSystem
            ? $this->settings->bool('ai.agent_extraction_enabled', true)
            : $this->settings->bool('ai.bank_extraction_enabled', true);
    }

    /** @return Collection<int, EvidenceFile> */
    private function activeGroup(EvidenceFile $evidence): Collection
    {
        if ($evidence->kind !== EvidenceKind::AgentSystem) return collect([$evidence]);

        return $evidence->transaction->evidenceFiles()
            ->where('kind',EvidenceKind::AgentSystem->value)
            ->whereNull('superseded_by_id')
            ->orderBy('id')
            ->get();
    }

    private function isFinalized(\App\Models\Transaction $transaction): bool
    {
        return in_array($transaction->status, [
            TransactionStatus::Completed,
            TransactionStatus::Rejected,
            TransactionStatus::Cancelled,
        ], true);
    }
}
