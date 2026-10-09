<?php
namespace App\Http\Controllers\Admin;

use App\Enums\EvidenceKind; use App\Enums\TransactionStatus; use App\Enums\TransactionType; use App\Http\Controllers\Controller; use App\Jobs\RecheckExternalPaymentsJob; use App\Models\Brand; use App\Models\Transaction; use App\Models\User; use App\Services\Banking\PaymentVerificationService; use App\Services\Transactions\CreditLedgerService; use App\Services\Transactions\EvidenceProcessor; use App\Services\Transactions\TransactionWorkflowService; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Illuminate\View\View; use Throwable;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $q=Transaction::with(['agent','brand','employee'])->latest();
        if($request->filled('status'))$q->where('status',$request->input('status')); if($request->filled('type'))$q->where('type',$request->input('type')); if($request->filled('brand'))$q->where('brand_id',$request->integer('brand')); if($request->filled('employee'))$q->where('employee_id',$request->integer('employee'));
        if($request->filled('q')){$term='%'.$request->string('q')->toString().'%';$q->where(function($x)use($term){$x->where('reference','like',$term)->orWhereHas('agent',fn($a)=>$a->where('agent_id','like',$term)->orWhere('username','like',$term))->orWhereHas('payments',fn($p)=>$p->where('transaction_id_raw','like',$term)->orWhere('normalized_transaction_id','like',$term));});}
        return view('admin.transactions.index',['transactions'=>$q->paginate(35)->withQueryString(),'brands'=>Brand::orderBy('name')->get(),'employees'=>User::where('role','employee')->orderBy('name')->get(),'types'=>TransactionType::cases(),'statuses'=>TransactionStatus::cases()]);
    }
    public function show(Transaction $transaction, CreditLedgerService $credits, \App\Services\Banking\AgentTopupReferenceService $referenceService): View
    {
        $transaction->load([
            'agent.brand','brand','employee',
            'payments.fromBank','payments.toBank','payments.receivingAccount','payments.evidenceFile','payments.duplicateOf.transaction',
            'evidenceFiles','events.actor',
            'correctionRequests.requester','correctionRequests.reviewer',
            'confirmations','issuedCreditRecord.repaymentAllocations.repaymentTransaction'
        ]);
        return view('admin.transactions.show',['transaction'=>$transaction,'currentOutstanding'=>$transaction->agent?$credits->outstanding($transaction->agent):null,'referenceReconciliation'=>$referenceService->analyze($transaction)]);
    }
    public function externalOverride(Request $request, Transaction $transaction, TransactionWorkflowService $workflow): RedirectResponse
    {
        abort_if(in_array($transaction->status,[TransactionStatus::Completed,TransactionStatus::Rejected,TransactionStatus::Cancelled],true),422,'Finalized transactions cannot be overridden.');
        $data=$request->validate(['note'=>['required','string','min:5','max:1000']]); try{$workflow->addAdminExternalOverride($transaction,$request->user(),$data['note']);}catch(Throwable $e){return back()->withErrors(['override'=>$e->getMessage()]);} return back()->with('success','Secondary verification override approved and recorded in the audit trail.');
    }
    public function reject(Request $request, Transaction $transaction, TransactionWorkflowService $workflow): RedirectResponse
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]); try{$workflow->reject($transaction,'admin_rejected',$data['reason']);}catch(Throwable $e){return back()->withErrors(['transaction'=>$e->getMessage()]);} return back()->with('success','Transaction rejected.');
    }
    public function revalidate(Request $request, Transaction $transaction, EvidenceProcessor $processor, TransactionWorkflowService $workflow): RedirectResponse
    {
        abort_if(in_array($transaction->status,[TransactionStatus::Completed,TransactionStatus::Rejected,TransactionStatus::Cancelled],true),422);
        try{
            $evidences=$transaction->evidenceFiles()->whereNotNull('extracted')->orderByRaw("CASE WHEN kind = 'agent_system' THEN 0 ELSE 1 END")->orderBy('sequence')->get();
            foreach($evidences as $e)$processor->reapplyExtracted($e,$request->user());
            $workflow->recalculate($transaction->fresh());
        }catch(Throwable $e){return back()->withErrors(['revalidate'=>$e->getMessage()]);}
        return back()->with('success','Transaction revalidated against current agent, bank-account and risk rules.');
    }
    public function retryExternal(Request $request, Transaction $transaction, PaymentVerificationService $payments, TransactionWorkflowService $workflow): RedirectResponse
    {
        abort_if(in_array($transaction->status,[TransactionStatus::Completed,TransactionStatus::Rejected,TransactionStatus::Cancelled],true),422);
        abort_unless($transaction->type->requiresBankEvidence(),422);
        RecheckExternalPaymentsJob::dispatch($transaction->id, null, $request->user()->id);
        return back()->with('success','Check.et recheck queued for eligible receipts, including those waiting after an earlier outage. Refresh this page for results.');
    }
}
