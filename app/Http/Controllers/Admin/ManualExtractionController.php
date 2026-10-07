<?php
namespace App\Http\Controllers\Admin;

use App\Enums\EvidenceKind;
use App\Http\Controllers\Controller;
use App\Models\EvidenceFile;
use App\Services\Transactions\EvidenceProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ManualExtractionController extends Controller
{
    public function store(Request $request, EvidenceFile $evidence, EvidenceProcessor $processor): RedirectResponse
    {
        $rules = $evidence->kind === EvidenceKind::AgentSystem ? [
            'agent_id'=>['required','string','max:120'], 'agent_username'=>['required','string','max:120'],
            'amount'=>['required','numeric','gt:0'], 'transaction_at'=>['required','date'], 'brand_hint'=>['nullable','string','max:120'],
        ] : [
            'from_bank'=>['required','string','max:120'], 'to_bank'=>['required','string','max:120'],
            'sender_account'=>['nullable','string','max:160'], 'sender_name'=>['nullable','string','max:160'],
            'receiver_account'=>['required','string','max:160'], 'receiver_name'=>['required','string','max:160'],
            'amount'=>['required','numeric','gt:0'], 'transaction_id'=>['required','string','max:160'], 'transaction_at'=>['required','date'],
        ];
        $payload=$request->validate($rules);
        $payload['quality']=['score'=>1,'critical_confidence'=>1];
        try { $processor->applyManualExtraction($evidence,$payload,$request->user()); }
        catch(Throwable $e){ return back()->withErrors(['manual_extraction'=>$e->getMessage()]); }
        return back()->with('success','Manual extraction applied and transaction revalidated through the same hard rules.');
    }
}
