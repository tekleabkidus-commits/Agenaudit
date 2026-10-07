<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectionRequestForm;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Services\Transactions\CorrectionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CorrectionController extends Controller
{
    public function store(CorrectionRequestForm $request, Transaction $transaction, CorrectionService $corrections): RedirectResponse
    {
        $this->authorize('update',$transaction);
        $payment = $request->filled('payment_record_id') ? PaymentRecord::where('transaction_id',$transaction->id)->findOrFail($request->integer('payment_record_id')) : null;
        $evidence = $request->filled('evidence_file_id') ? EvidenceFile::where('transaction_id',$transaction->id)->findOrFail($request->integer('evidence_file_id')) : null;
        try {
            $corrections->request($request->user(),$transaction,$request->string('field')->toString(),$request->input('proposed_value'),$request->string('reason')->toString(),$payment,$evidence);
        } catch (Throwable $e) {
            return back()->withErrors(['correction'=>$e->getMessage()]);
        }
        return back()->with('success','Correction request sent to Admin. The AI value remains unchanged until approval.');
    }
}
