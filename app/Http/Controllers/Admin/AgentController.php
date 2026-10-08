<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentBrandHistory;
use App\Models\Brand;
use App\Services\Audit\AuditLogger;
use App\Services\Transactions\CreditLedgerService;
use App\Support\Normalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function index(Request $request, CreditLedgerService $credits): View
    {
        $q=Agent::with('brand')->orderBy('agent_id');
        if ($request->filled('brand')) $q->where('brand_id',$request->integer('brand'));
        if ($request->filled('q')) {
            $needle='%'.$request->string('q')->toString().'%';
            $q->where(fn($x)=>$x->where('agent_id','like',$needle)->orWhere('username','like',$needle));
        }
        $agents=$q->paginate(40)->withQueryString();
        $balances=$credits->outstandingMap($agents->getCollection()->pluck('id'));
        $agents->getCollection()->transform(function(Agent $agent) use($balances){ $agent->outstanding_credit=$balances[$agent->id]??0.0; return $agent; });
        return view('admin.agents.index',['agents'=>$agents,'brands'=>Brand::where('is_active',true)->orderBy('name')->get()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['brand_id'=>['required',Rule::exists('brands','id')->where(fn($q)=>$q->where('is_active',true))],'agent_id'=>['required','string','max:120'],'username'=>['required','string','max:120'],'credit_limit'=>['nullable','numeric','min:0'],'credit_due_days'=>['nullable','integer','min:0','max:365'],'commission_monthly_limit'=>['nullable','integer','min:1','max:31']]);
        $id=Normalizer::identifier($data['agent_id']); $user=Normalizer::identifier($data['username']);
        if (Agent::where('agent_id_normalized',$id)->exists()) return back()->withErrors(['agent_id'=>'Agent ID must be globally unique across all brands.'])->withInput();
        if (Agent::where('username_normalized',$user)->exists()) return back()->withErrors(['username'=>'Agent username must be globally unique across all brands.'])->withInput();
        $brand = Brand::findOrFail($data['brand_id']);
        $commissionEnabled = $request->has('commission_enabled')
            ? $request->boolean('commission_enabled')
            : $brand->commission_enabled;
        $commissionLimit = $data['commission_monthly_limit'] ?? $brand->commission_monthly_limit;
        $agent=Agent::create(['brand_id'=>$data['brand_id'],'agent_id'=>$data['agent_id'],'agent_id_normalized'=>$id,'username'=>$data['username'],'username_normalized'=>$user,'credit_enabled'=>$request->boolean('credit_enabled',true),'credit_limit'=>$data['credit_limit']??null,'credit_due_days'=>$data['credit_due_days']??null,'commission_enabled'=>$commissionEnabled,'commission_monthly_limit'=>$commissionLimit,'is_active'=>true]);
        $audit->log('agent.created',$agent,null,$agent->toArray());
        return back()->with('success','Agent added.');
    }

    public function edit(Agent $agent, CreditLedgerService $credits): View
    {
        $agent->load(['brand','brandHistories.fromBrand','brandHistories.toBrand','creditLedgerEntries.transaction','transactions.employee']);

        $creditRecords = $agent->creditRecords()
            ->with(['issueTransaction','repaymentAllocations.repaymentTransaction'])
            ->latest('issued_at')
            ->get()
            ->each(fn($record) => $record->setAttribute('aging_status', $credits->agingStatus($record)));

        return view('admin.agents.edit',[
            'agent'=>$agent,
            'brands'=>Brand::orderBy('name')->get(),
            'outstanding'=>$credits->outstanding($agent),
            'creditRecords'=>$creditRecords,
            'commissionUsedThisMonth'=>$agent->transactions()->where('type',\App\Enums\TransactionType::Commission->value)->where('status',\App\Enums\TransactionStatus::Completed->value)->whereBetween('completed_at',[now()->startOfMonth(),now()->endOfMonth()])->count(),
        ]);
    }

    public function update(Request $request, Agent $agent, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['brand_id'=>['required','exists:brands,id'],'agent_id'=>['required','string','max:120'],'username'=>['required','string','max:120'],'credit_limit'=>['nullable','numeric','min:0'],'credit_due_days'=>['nullable','integer','min:0','max:365'],'commission_monthly_limit'=>['required','integer','min:1','max:31'],'move_reason'=>[Rule::requiredIf((int)$request->input('brand_id') !== $agent->brand_id),'nullable','string','max:500']]);
        $id=Normalizer::identifier($data['agent_id']); $user=Normalizer::identifier($data['username']);
        if (Agent::where('agent_id_normalized',$id)->whereKeyNot($agent->id)->exists()) return back()->withErrors(['agent_id'=>'Agent ID already belongs to another platform agent.']);
        if (Agent::where('username_normalized',$user)->whereKeyNot($agent->id)->exists()) return back()->withErrors(['username'=>'Agent username already belongs to another platform agent.']);
        $before=$agent->toArray();
        DB::transaction(function() use($agent,$data,$id,$user,$request){
            if ((int)$data['brand_id'] !== $agent->brand_id) {
                AgentBrandHistory::create(['agent_id'=>$agent->id,'from_brand_id'=>$agent->brand_id,'to_brand_id'=>$data['brand_id'],'changed_by'=>$request->user()->id,'reason'=>$data['move_reason'] ?: 'Admin brand move','changed_at'=>now()]);
            }
            $agent->update(['brand_id'=>$data['brand_id'],'agent_id'=>$data['agent_id'],'agent_id_normalized'=>$id,'username'=>$data['username'],'username_normalized'=>$user,'credit_enabled'=>$request->boolean('credit_enabled'),'credit_limit'=>$data['credit_limit']??null,'credit_due_days'=>$data['credit_due_days']??null,'commission_enabled'=>$request->boolean('commission_enabled'),'commission_monthly_limit'=>$data['commission_monthly_limit'],'is_active'=>$request->boolean('is_active')]);
        });
        $audit->log('agent.updated',$agent,$before,$agent->fresh()->toArray());
        return back()->with('success','Agent updated.');
    }
}
