<?php

namespace App\Services\Transactions;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\ClearerScreenshotRequiredException;
use App\Exceptions\HardRejectException;
use App\Exceptions\ReviewRequiredException;
use App\Models\EvidenceFile;
use App\Models\User;
use App\Services\AI\ExtractionGuard;
use App\Services\AI\VisionExtractorInterface;
use App\Services\Banking\PaymentVerificationService;
use App\Services\SettingsService;
use Throwable;

class EvidenceProcessor
{
    public function __construct(
        private VisionExtractorInterface $vision,
        private ExtractionGuard $guard,
        private AgentIdentityService $agents,
        private PaymentVerificationService $payments,
        private TransactionWorkflowService $workflow,
        private TransactionEventLogger $events,
        private SettingsService $settings,
    ) {}

    public function process(EvidenceFile $evidence): void
    {
        $transaction = $evidence->transaction;
        if (!$this->settings->bool('ai.enabled', (bool) config('services.ai.enabled'))) {
            $evidence->update(['status'=>EvidenceStatus::PendingAdminExtraction,'failure_reason'=>'AI extraction is disabled.']);
            $transaction->update(['status'=>TransactionStatus::PendingAdminExtraction,'review_reason'=>'AI is disabled; Admin extraction is required.']);
            $this->events->add($transaction, 'ai_disabled', 'Evidence queued for Admin extraction because AI is disabled.');
            return;
        }

        $evidence->update(['status'=>EvidenceStatus::Processing,'failure_reason'=>null]);
        try {
            $payload = $this->vision->extract($evidence);
            $evidence->update([
                'raw_ai_response'=>$payload,
                'quality_score'=>(float) data_get($payload,'quality.score',0),
                'critical_confidence'=>(float) data_get($payload,'quality.critical_confidence',0),
            ]);
            $this->guard->assertUsable($payload, $evidence->kind);
            $evidence->update(['status'=>EvidenceStatus::Extracted,'extracted'=>$payload]);
            $this->applyExtracted($evidence, $payload);
        } catch (ClearerScreenshotRequiredException $e) {
            $evidence->update(['status'=>EvidenceStatus::NeedsReupload,'failure_reason'=>$e->getMessage()]);
            $transaction->update(['status'=>TransactionStatus::NeedsClearerScreenshot,'review_reason'=>$e->getMessage()]);
            $this->events->add($transaction, 'clearer_screenshot_required', $e->getMessage(), ['code'=>$e->reasonCode]);
        } catch (HardRejectException $e) {
            $evidence->update(['failure_reason'=>$e->getMessage()]);
            $this->workflow->reject($transaction, $e->codeName, $e->getMessage());
        } catch (ReviewRequiredException $e) {
            $evidence->update(['failure_reason'=>$e->getMessage()]);
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>$e->getMessage()]);
            $this->events->add($transaction, 'admin_review_required', $e->getMessage(), ['code'=>$e->reasonCode]);
        } catch (Throwable $e) {
            report($e);
            $evidence->update(['status'=>EvidenceStatus::Failed,'failure_reason'=>'Evidence processing failed.']);
            $transaction->update(['status'=>TransactionStatus::PendingAdminReview,'review_reason'=>'Evidence processor failed; Admin review required.']);
            $this->events->add($transaction, 'processing_failed', 'Evidence processing failed and was routed to Admin review.');
        }
    }

    public function applyManualExtraction(EvidenceFile $evidence, array $payload, User $admin): void
    {
        if ($evidence->status !== EvidenceStatus::PendingAdminExtraction) throw new \RuntimeException('Manual extraction is only allowed when AI is disabled for this evidence.');
        $evidence->update(['status'=>EvidenceStatus::Extracted,'extracted'=>$payload,'raw_ai_response'=>null,'quality_score'=>1,'critical_confidence'=>1,'failure_reason'=>null]);
        $this->events->add($evidence->transaction, 'admin_manual_extraction', 'Admin supplied extraction while AI was disabled.', ['evidence_id'=>$evidence->id], $admin);
        $this->applyExtracted($evidence, $payload);
    }


    public function reapplyExtracted(EvidenceFile $evidence, User $admin): void
    {
        if (!$evidence->extracted) throw new \RuntimeException('This evidence has no extracted data to revalidate.');
        if (in_array($evidence->transaction->status, [TransactionStatus::Completed, TransactionStatus::Rejected, TransactionStatus::Cancelled], true)) throw new \RuntimeException('Finalized transactions cannot be revalidated.');
        $this->events->add($evidence->transaction, 'admin_revalidation', 'Admin requested revalidation of previously extracted evidence.', ['evidence_id'=>$evidence->id], $admin);
        $this->applyExtracted($evidence, $evidence->extracted);
    }

    private function applyExtracted(EvidenceFile $evidence, array $payload): void
    {
        $transaction = $evidence->transaction->fresh(['brand','agent']);
        if ($evidence->kind === EvidenceKind::AgentSystem) $this->agents->apply($transaction, $payload);
        else $this->payments->apply($transaction, $evidence, $payload);
        $this->workflow->recalculate($transaction->fresh());
    }
}
