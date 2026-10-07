<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CreditRecord;
use App\Services\SettingsService;
use App\Services\Transactions\CreditLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditController extends Controller
{
    public function index(Request $request, CreditLedgerService $credits, SettingsService $settings): View
    {
        $q = CreditRecord::query()
            ->with(['agent.brand','issueTransaction.employee'])
            ->latest('issued_at');

        if ($request->filled('brand')) {
            $brandId = $request->integer('brand');
            $q->whereHas('agent', fn($agent)=>$agent->where('brand_id',$brandId));
        }

        $status = $request->string('status','open')->toString();
        if ($status === 'open') {
            $q->whereIn('status',['unpaid','partial']);
        } elseif (in_array($status,['unpaid','partial','paid'],true)) {
            $q->where('status',$status);
        }

        if ($request->filled('q')) {
            $needle = '%'.$request->string('q')->toString().'%';
            $q->whereHas('agent', fn($agent)=>$agent
                ->where('agent_id','like',$needle)
                ->orWhere('username','like',$needle));
        }

        $records = $q->paginate(40)->withQueryString();
        $records->getCollection()->each(function(CreditRecord $record) use ($credits) {
            $record->setAttribute('aging_status',$credits->agingStatus($record));
        });

        $summaryQ = CreditRecord::query();
        if ($request->filled('brand')) {
            $brandId = $request->integer('brand');
            $summaryQ->whereHas('agent', fn($agent)=>$agent->where('brand_id',$brandId));
        }

        $openQ = (clone $summaryQ)->whereIn('status',['unpaid','partial']);
        $criticalDays = $settings->int('credit.critical_overdue_days',7);

        return view('admin.credits.index',[
            'records'=>$records,
            'brands'=>Brand::where('is_active',true)->orderBy('name')->get(),
            'status'=>$status,
            'summary'=>[
                'outstanding'=>(float)(clone $openQ)->sum('outstanding_amount'),
                'open_count'=>(clone $openQ)->count(),
                'overdue_count'=>(clone $openQ)->whereNotNull('due_at')->where('due_at','<',now())->count(),
                'critical_count'=>(clone $openQ)->whereNotNull('due_at')->where('due_at','<=',now()->subDays($criticalDays))->count(),
            ],
        ]);
    }
}
