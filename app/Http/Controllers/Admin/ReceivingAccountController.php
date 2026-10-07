<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\ReceivingAccount;
use App\Services\Audit\AuditLogger;
use App\Support\Normalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReceivingAccountController extends Controller
{
    public function index(): View
    {
        return view('admin.receiving-accounts.index',['accounts'=>ReceivingAccount::with(['bank','brands'])->orderByDesc('id')->paginate(40),'banks'=>Bank::where('is_active',true)->orderBy('name')->get(),'brands'=>Brand::where('is_active',true)->orderBy('name')->get()]);
    }
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['bank_id'=>['required','exists:banks,id'],'account_number'=>['required','string','max:120'],'account_name'=>['required','string','max:160'],'name_aliases'=>['nullable','string'],'brand_ids'=>['required','array','min:1'],'brand_ids.*'=>['integer','exists:brands,id']]);
        $normalized=Normalizer::account($data['account_number']);
        if (ReceivingAccount::where('bank_id',$data['bank_id'])->where('normalized_account_number',$normalized)->exists()) return back()->withErrors(['account_number'=>'This receiving account already exists for the selected bank.'])->withInput();
        $account=ReceivingAccount::create(['bank_id'=>$data['bank_id'],'account_number'=>$data['account_number'],'normalized_account_number'=>$normalized,'account_name'=>$data['account_name'],'normalized_account_name'=>Normalizer::name($data['account_name']),'name_aliases'=>$this->aliases($data['name_aliases']??null),'is_active'=>true]);
        $account->brands()->sync($data['brand_ids']);
        $audit->log('receiving_account.created',$account,null,$account->load('brands')->toArray());
        return back()->with('success','Approved receiving account added.');
    }
    public function update(Request $request, ReceivingAccount $receivingAccount, AuditLogger $audit): RedirectResponse
    {
        $data=$request->validate(['bank_id'=>['required','exists:banks,id'],'account_number'=>['required','string','max:120'],'account_name'=>['required','string','max:160'],'name_aliases'=>['nullable','string'],'brand_ids'=>['required','array','min:1'],'brand_ids.*'=>['integer','exists:brands,id']]);
        $normalized=Normalizer::account($data['account_number']);
        $duplicate=ReceivingAccount::where('bank_id',$data['bank_id'])->where('normalized_account_number',$normalized)->whereKeyNot($receivingAccount->id)->exists();
        if ($duplicate) return back()->withErrors(['account_number'=>'This receiving account already exists for the selected bank.']);
        $before=$receivingAccount->load('brands')->toArray();
        $receivingAccount->update(['bank_id'=>$data['bank_id'],'account_number'=>$data['account_number'],'normalized_account_number'=>$normalized,'account_name'=>$data['account_name'],'normalized_account_name'=>Normalizer::name($data['account_name']),'name_aliases'=>$this->aliases($data['name_aliases']??null),'is_active'=>$request->boolean('is_active')]);
        $receivingAccount->brands()->sync($data['brand_ids']);
        $audit->log('receiving_account.updated',$receivingAccount,$before,$receivingAccount->load('brands')->toArray());
        return back()->with('success','Receiving account updated.');
    }
    private function aliases(?string $value): array { return collect(explode(',',(string)$value))->map(fn($v)=>trim($v))->filter()->values()->all(); }
}
