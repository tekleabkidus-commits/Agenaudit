<?php

namespace App\Http\Controllers\Employee;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessEvidenceJob;
use App\Models\EvidenceFile;
use App\Models\Transaction;
use App\Services\Transactions\EvidenceStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EvidenceController extends Controller
{
    public function agent(Request $request, Transaction $transaction, EvidenceStorageService $storage): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_unless($transaction->type->requiresAgentScreenshot(),404);

        $data=$request->validate([
            'screenshots'=>['required','array','min:1','max:'.config('agent_audit.evidence.max_agent_proof_images_per_transaction',5)],
            'screenshots.*'=>['required','file','mimetypes:image/jpeg,image/png,image/webp','max:'.config('agent_audit.evidence.max_kb',12288)],
        ]);

        $old=$transaction->evidenceFiles()->where('kind',EvidenceKind::AgentSystem->value)
            ->whereNull('superseded_by_id')->orderBy('id')->get();

        if ($old->isNotEmpty() && !$old->contains(fn ($item) =>
            in_array($item->status,[EvidenceStatus::NeedsReupload,EvidenceStatus::Failed],true)
        )) {
            throw ValidationException::withMessages([
                'screenshots'=>'Agent proof is already active. Replace proof images only when a clearer upload is requested.',
            ]);
        }

        $first=null;
        foreach ($data['screenshots'] as $file) {
            $stored=$storage->store($transaction,$file,EvidenceKind::AgentSystem);
            $first ??= $stored;
        }

        // Supersede the whole previous batch, retaining originals as audit history.
        foreach ($old as $previous) {
            $previous->update([
                'superseded_by_id'=>$first->id,
                'status'=>EvidenceStatus::Superseded,
            ]);
        }

        $transaction->update(['status'=>TransactionStatus::Processing,'review_reason'=>null]);
        ProcessEvidenceJob::dispatch($first->id);

        return back()->with('success',count($data['screenshots']).' agent proof image(s) uploaded for combined AI reading.');
    }

    public function banks(Request $request, Transaction $transaction, EvidenceStorageService $storage): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_unless($transaction->type->requiresBankEvidence(),404);
        abort_unless($transaction->agent_id && $transaction->brand_id,422,'Agent must be identified first.');
        $data = $request->validate([
            'screenshots'=>['required','array','min:1','max:'.config('agent_audit.evidence.max_bank_screenshots_per_transaction',12)],
            'screenshots.*'=>['required','file','mimetypes:image/jpeg,image/png,image/webp','max:'.config('agent_audit.evidence.max_kb',12288)],
        ]);
        foreach ($data['screenshots'] as $file) {
            $evidence = $storage->store($transaction,$file,EvidenceKind::BankPayment);
            ProcessEvidenceJob::dispatch($evidence->id);
        }
        $transaction->update(['status'=>TransactionStatus::Processing,'review_reason'=>null]);
        return back()->with('success',count($data['screenshots']).' bank screenshot(s) uploaded for automatic verification.');
    }

    public function confirm(Request $request, EvidenceFile $evidence, \App\Services\Transactions\EvidenceProcessor $processor): RedirectResponse
    {
        $this->authorize('update',$evidence->transaction);

        try {
            $processor->confirmEmployeeExtraction($evidence, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['evidence'=>$e->getMessage()]);
        }

        return back()->with('success','AI extraction confirmed and applied.');
    }

    public function clearer(Request $request, EvidenceFile $evidence, \App\Services\Transactions\EvidenceProcessor $processor): RedirectResponse
    {
        $this->authorize('update',$evidence->transaction);

        try {
            $processor->requestClearerScreenshot($evidence, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['evidence'=>$e->getMessage()]);
        }

        return back()->with('success','Upload a clearer screenshot.');
    }

    public function retry(Request $request, EvidenceFile $evidence): RedirectResponse
    {
        $this->authorize('update',$evidence->transaction);
        abort_unless(in_array($evidence->status,[EvidenceStatus::Failed,EvidenceStatus::Queued],true),422);
        $evidence->update(['status'=>EvidenceStatus::Queued,'failure_reason'=>null]);
        ProcessEvidenceJob::dispatch($evidence->id);
        return back()->with('success','Evidence processing queued again.');
    }
}
