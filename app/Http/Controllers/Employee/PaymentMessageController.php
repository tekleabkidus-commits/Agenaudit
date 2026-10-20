<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\PaymentInput;
use App\Models\Transaction;
use App\Services\Banking\PaymentMessageVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PaymentMessageController extends Controller
{
    public function submit(Request $request,Transaction $transaction,PaymentMessageVerificationService $verification): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_unless($transaction->type->requiresBankEvidence() && $transaction->agent_id,422);
        $request->merge(['messages'=>array_values(array_filter(
            (array)$request->input('messages',[]),
            fn ($item)=>is_string($item) && trim($item)!==''
        ))]);
        $data=$request->validate([
            'messages'=>['required','array','min:1','max:3'],
            'messages.*'=>['required','string','max:6000'],
        ]);

        $completed=0;
        $pending=0;
        foreach ($data['messages'] as $message) {
            $result=$verification->submit($transaction,$request->user(),$message);
            if ($result->status==='verified') $completed++;
            else $pending++;
        }
        return back()->with('success',"{$completed} receipt(s) verified; {$pending} awaiting review or recheck. Verified amounts are counted only when receiver validation passes.");
    }

    public function recheck(Request $request,Transaction $transaction,PaymentInput $input,PaymentMessageVerificationService $verification): RedirectResponse
    {
        $this->authorize('update',$transaction);
        abort_unless($transaction->id===$input->transaction_id,404);
        abort_unless(in_array($input->status,['unavailable','review','pending'],true),422);
        $result=$verification->process($input);
        return back()->with('success','Check.et recheck completed: '.str_replace('_',' ',$result->status).'.');
    }
}
