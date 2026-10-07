<?php

namespace App\Http\Controllers\Employee;

use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Transaction;
use App\Services\Transactions\CreditLedgerService;
use App\Services\Transactions\TransactionWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $brandIds = $request->user()->brands()->pluck('brands.id');
        $transactions = Transaction::with(['agent','brand'])
            ->where('employee_id',$request->user()->id)
            ->where(function ($q) use ($brandIds) {
                $q->whereNull('brand_id')->orWhereIn('brand_id',$brandIds);
            })
            ->when($request->filled('type'), fn($q)=>$q->where('type',$request->input('type')))
            ->when($request->filled('status'), fn($q)=>$q->where('status',$request->input('status')))
            ->latest()
            ->paginate(25)
            ->withQueryString();
        return view('employee.transactions.index', compact('transactions'));
    }

    public function create(Request $request, CreditLedgerService $credits): View
    {
        $user = $request->user()->load('brands');
        $allowedBrandIds = $user->brands->pluck('id');
        $outstandingAgents = $credits->agentsWithOutstanding()
            ->filter(fn ($row) => $allowedBrandIds->contains($row['agent']->brand_id))
            ->values();

        return view('employee.transactions.create', [
            'types'=>TransactionType::cases(),
            'outstandingAgents'=>$outstandingAgents,
            'assignedBrands'=>$user->brands,
        ]);
    }

    public function store(Request $request, TransactionWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'type'=>['required',Rule::enum(TransactionType::class)],
            'repayment_agent_id'=>['nullable','integer','exists:agents,id'],
        ]);
        $type = TransactionType::from($validated['type']);
        $agent = null;
        if ($type === TransactionType::CreditRepayment) {
            $request->validate(['repayment_agent_id'=>['required','integer','exists:agents,id']]);
            $agent = Agent::findOrFail($validated['repayment_agent_id']);
            abort_unless($request->user()->canAccessBrand($agent->brand_id), 403, 'You are not assigned to this agent brand.');
        }
        $transaction = $workflow->create($request->user(), $type, $agent);
        return redirect()->route('employee.transactions.show',$transaction);
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
