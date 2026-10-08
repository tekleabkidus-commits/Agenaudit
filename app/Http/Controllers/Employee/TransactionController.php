<?php

namespace App\Http\Controllers\Employee;

use App\Enums\EvidenceKind;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessEvidenceJob;
use App\Models\Agent;
use App\Models\Transaction;
use App\Services\Transactions\CreditLedgerService;
use App\Services\Transactions\EvidenceStorageService;
use App\Services\Transactions\TransactionWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;
use RuntimeException;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $brandIds = $request->user()->brands()->pluck('brands.id');

        $base = Transaction::query()
            ->where('employee_id', $request->user()->id)
            ->where(function ($q) use ($brandIds) {
                $q->whereNull('brand_id')->orWhereIn('brand_id', $brandIds);
            })
            // Old empty drafts remain in the audit trail but are not a
            // transaction in Employee History until evidence exists.
            ->where(function ($q) {
                $q->where('status', '!=', TransactionStatus::Draft->value)
                    ->orWhereHas('evidenceFiles');
            });

        $agentIds = (clone $base)->whereNotNull('agent_id')
            ->distinct()->pluck('agent_id');

        $agents = Agent::query()
            ->with('brand')
            ->whereIn('id', $agentIds)
            ->orderBy('agent_id')
            ->get();

        $transactions = (clone $base)
            ->with(['agent','brand'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('agent') && ctype_digit((string) $request->input('agent')), fn ($q) => $q->where('agent_id', (int) $request->input('agent')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('employee.transactions.index', compact('transactions', 'agents'));
    }

    /**
     * A transaction type is navigation, not a persisted draft.
     * Nothing is stored until the first valid screenshot is uploaded.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $selected = TransactionType::tryFrom((string) $request->query('type',''));
        if ($selected) {
            return redirect()->route('employee.transactions.start', ['type'=>$selected->value]);
        }

        return view('employee.transactions.create', [
            'types'=>TransactionType::cases(),
            'assignedBrands'=>$request->user()->brands()->where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function start(Request $request, TransactionType $type, CreditLedgerService $credits): View
    {
        $user = $request->user();
        $assignedBrands = $user->brands()->where('is_active',true)->orderBy('name')->get();

        abort_if($assignedBrands->isEmpty(), 403, 'Ask Admin to assign at least one active brand before starting a transaction.');

        if ($type === TransactionType::CreditRepayment) {
            $allowedBrandIds = $assignedBrands->pluck('id');
            $outstandingAgents = $credits->agentsWithOutstanding()
                ->filter(fn ($row) => $allowedBrandIds->contains($row['agent']->brand_id) && $row['agent']->brand?->is_active)
                ->values();

            return view('employee.transactions.repayment-select', [
                'outstandingAgents'=>$outstandingAgents,
                'assignedBrands'=>$assignedBrands,
            ]);
        }

        return view('employee.transactions.initial-evidence', [
            'type'=>$type,
            'assignedBrands'=>$assignedBrands,
            'agent'=>null,
            'outstanding'=>null,
        ]);
    }

    public function repaymentEvidence(Request $request, Agent $agent, CreditLedgerService $credits): View
    {
        $agent->load('brand');
        abort_unless(
            $agent->is_active && $agent->brand?->is_active && $request->user()->canAccessBrand($agent->brand_id),
            403,
            'This agent is not available to you.'
        );

        $outstanding = $credits->outstanding($agent);
        abort_unless($outstanding > 0, 422, 'This agent has no outstanding credit to repay.');

        return view('employee.transactions.initial-evidence', [
            'type'=>TransactionType::CreditRepayment,
            'assignedBrands'=>$request->user()->brands()->where('is_active',true)->orderBy('name')->get(),
            'agent'=>$agent,
            'outstanding'=>$outstanding,
        ]);
    }

    /**
     * Validate and store the first screenshot in the same DB transaction
     * as the financial record. Invalid/missing evidence never leaves a draft.
     */
    public function storeInitialEvidence(
        Request $request,
        TransactionType $type,
        TransactionWorkflowService $workflow,
        EvidenceStorageService $storage,
        CreditLedgerService $credits
    ): RedirectResponse {
        $user = $request->user();

        if ($type === TransactionType::CreditRepayment) {
            $data = $request->validate([
                'repayment_agent_id'=>['required','integer','exists:agents,id'],
                'screenshots'=>['required','array','min:1','max:'.config('agent_audit.evidence.max_bank_screenshots_per_transaction',12)],
                'screenshots.*'=>['required','file','mimetypes:image/jpeg,image/png,image/webp','max:'.config('agent_audit.evidence.max_kb',12288)],
            ]);

            $agent = Agent::with('brand')->findOrFail($data['repayment_agent_id']);
            abort_unless(
                $agent->is_active && $agent->brand?->is_active && $user->canAccessBrand($agent->brand_id),
                403,
                'This agent is not assigned to you or is inactive.'
            );
            abort_unless($credits->outstanding($agent) > 0, 422, 'This agent has no outstanding credit.');

            $uploads = $data['screenshots'];
            $kind = EvidenceKind::BankPayment;
        } else {
            $data = $request->validate([
                'screenshots'=>['required','array','min:1','max:'.config('agent_audit.evidence.max_agent_proof_images_per_transaction',5)],
                'screenshots.*'=>['required','file','mimetypes:image/jpeg,image/png,image/webp','max:'.config('agent_audit.evidence.max_kb',12288)],
            ]);

            abort_unless(
                $user->brands()->where('is_active',true)->exists(),
                403,
                'Ask Admin to assign an active brand.'
            );

            $agent = null;
            $uploads = $data['screenshots'];
            $kind = EvidenceKind::AgentSystem;
        }

        $transaction = null;
        try {
            [$transaction, $evidenceIds] = DB::transaction(function () use ($workflow, $storage, $user, $type, $agent, $uploads, $kind, &$transaction): array {
                $transaction = $workflow->create($user, $type, $agent);
                $evidenceIds = [];

                foreach ($uploads as $file) {
                    $stored = $storage->store($transaction, $file, $kind);
                    $evidenceIds[] = $stored->id;
                }

                // Initial uploads are Processing, not empty Draft records.
                $transaction->update(['status'=>TransactionStatus::Processing]);
                return [$transaction, $evidenceIds];
            });
        } catch (Throwable $exception) {
            // Clean up any uploads written to object storage before the DB rolled back.
            if ($transaction) {
                Storage::disk(config('agent_audit.evidence.disk', 'private'))
                    ->deleteDirectory('evidence/'.$transaction->reference);
            }
            throw $exception;
        }

        // Agent proof images describe ONE transaction and must be extracted
        // together as a single AI request. Each bank receipt remains separate.
        // Queue only after database commit to avoid processing races.
        $queueIds = $kind === EvidenceKind::AgentSystem ? [reset($evidenceIds)] : $evidenceIds;
        foreach ($queueIds as $evidenceId) {
            ProcessEvidenceJob::dispatch($evidenceId);
        }

        return redirect()->route('employee.transactions.show', $transaction)
            ->with('success', 'Evidence saved and queued for verification.');
    }

    public function show(Request $request, Transaction $transaction, CreditLedgerService $credits): View
    {
        $this->authorize('view',$transaction);
        $transaction->load(['agent.brand','brand','payments.fromBank','payments.toBank','payments.receivingAccount','evidenceFiles','events.actor','correctionRequests']);
        $currentOutstanding = $transaction->agent ? $credits->outstanding($transaction->agent) : null;

        $commissionUsedThisMonth = null;
        $commissionRemainingThisMonth = null;
        if ($transaction->agent) {
            $commissionUsedThisMonth = $transaction->agent->transactions()
                ->where('type', TransactionType::Commission->value)
                ->where('status', TransactionStatus::Completed->value)
                ->whereBetween('completed_at',[now()->startOfMonth(),now()->endOfMonth()])
                ->count();

            $commissionRemainingThisMonth = max(
                0,
                (int)$transaction->agent->commission_monthly_limit - $commissionUsedThisMonth
            );
        }

        return view('employee.transactions.show', compact(
            'transaction',
            'currentOutstanding',
            'commissionUsedThisMonth',
            'commissionRemainingThisMonth'
        ));
    }

    public function reason(Request $request, Transaction $transaction, TransactionWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_unless($transaction->type === TransactionType::Withdrawal, 404);
        $reasons = array_keys(config('agent_audit.withdrawal_reasons', []));
        $data = $request->validate([
            'reason_code'=>['required',Rule::in($reasons)],
            'reason_note'=>[
                Rule::requiredIf($request->input('reason_code') === 'other'),
                'nullable','string','min:3','max:1000',
            ],
        ]);

        $label = config('agent_audit.withdrawal_reasons.'.$data['reason_code'], $data['reason_code']);
        $note = trim((string)($data['reason_note'] ?? ''));
        $transaction->update([
            'withdrawal_reason_code'=>$data['reason_code'],
            'withdrawal_reason_note'=>$note !== '' ? $note : null,
            'reason'=>$note !== '' ? $label.' — '.$note : $label,
        ]);
        $workflow->recalculate($transaction);
        return back()->with('success','Withdrawal reason saved.');
    }

    public function finalize(Request $request, Transaction $transaction, TransactionWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('update',$transaction);
        if ($transaction->risk_level === RiskLevel::Critical) {
            $data = $request->validate([
                'critical_confirmation_1'=>['accepted'],
                'critical_confirmation_2'=>['accepted'],
                'password'=>['required','string'],
            ]);
            if (!Hash::check($data['password'], $request->user()->password)) return back()->withErrors(['password'=>'Password confirmation failed.']);
            $workflow->confirmCritical($transaction, $request->user());
        }
        try {
            $workflow->finalize($transaction,$request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['transaction'=>$e->getMessage()]);
        }
        return redirect()->route('employee.transactions.show',$transaction)->with('success','Transaction completed.');
    }

    public function cancel(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_if(in_array($transaction->status,[TransactionStatus::Completed,TransactionStatus::Rejected,TransactionStatus::Cancelled],true),422);
        $transaction->update(['status'=>TransactionStatus::Cancelled]);
        return redirect()->route('employee.transactions.index')->with('success','Transaction cancelled.');
    }
}
