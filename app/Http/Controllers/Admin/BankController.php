<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BankController extends Controller
{
    public function index(): View { return view('admin.banks.index',['banks'=>Bank::withCount('receivingAccounts')->orderBy('name')->get()]); }
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['code'=>Str::upper(trim((string)$request->input('code')))]);
        $data=$request->validate(['name'=>['required','string','max:120'],'code'=>['required','string','max:40','unique:banks,code'],'aliases'=>['nullable','string'],'check_et_code'=>['nullable','string','max:40'],'check_et_account_source'=>['nullable',Rule::in(['receiving_account','sender_account','none'])]]);
        $bank=Bank::create(['name'=>$data['name'],'code'=>Str::upper($data['code']),'aliases'=>$this->aliases($data['aliases']??null),'check_et_code'=>$data['check_et_code']??null,'check_et_enabled'=>$request->boolean('check_et_enabled'),'check_et_requires_account'=>$request->boolean('check_et_requires_account'),'check_et_account_source'=>$data['check_et_account_source']??'none','is_active'=>true]);
        $audit->log('bank.created',$bank,null,$bank->toArray());
        return back()->with('success','Bank added.');
    }
    public function update(Request $request, Bank $bank, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['code'=>Str::upper(trim((string)$request->input('code')))]);
        $data=$request->validate(['name'=>['required','string','max:120'],'code'=>['required','string','max:40',Rule::unique('banks','code')->ignore($bank->id)],'aliases'=>['nullable','string'],'check_et_code'=>['nullable','string','max:40'],'check_et_account_source'=>['nullable',Rule::in(['receiving_account','sender_account','none'])]]);
        $before=$bank->toArray();
        $bank->update(['name'=>$data['name'],'code'=>Str::upper($data['code']),'aliases'=>$this->aliases($data['aliases']??null),'check_et_code'=>$data['check_et_code']??null,'check_et_enabled'=>$request->boolean('check_et_enabled'),'check_et_requires_account'=>$request->boolean('check_et_requires_account'),'check_et_account_source'=>$data['check_et_account_source']??'none','is_active'=>$request->boolean('is_active')]);
        $audit->log('bank.updated',$bank,$before,$bank->toArray());
        return back()->with('success','Bank updated.');
    }
    private function aliases(?string $value): array { return collect(explode(',',(string)$value))->map(fn($v)=>trim($v))->filter()->values()->all(); }
}
