<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EvidenceStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DateRange;
use App\Services\SettingsService;
use App\Services\Transactions\CreditLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    private function resolveRange(Request $request): array
    {
        $preset = $request->string('date','this_month')->toString();
        $from = $request->input('from');
        $to = $request->input('to');

        if ($preset === 'custom') {
            $from = $from ?: now()->startOfMonth()->toDateString();
            $to = $to ?: now()->toDateString();
        }

        return [
            DateRange::fromPreset($preset,$from,$to),
            $preset,
            $from,
            $to,
        ];
    }

    private function filtered(Request $request): array
    {
        [$range,$preset,$from,$to] = $this->resolveRange($request);

        $brandId = $request->filled('brand') && $request->input('brand') !== 'total'
            ? $request->integer('brand')
            : null;

        $kind = $request->string('kind','all')->toString();

        $query = Transaction::with(['agent','brand','employee'])
            ->where('status',TransactionStatus::Completed->value)
            ->whereBetween('completed_at',[$range->start,$range->end]);

        if ($brandId) $query->where('brand_id',$brandId);

        match($kind) {
            'deposit' => $query->where('type',TransactionType::PaidTopup->value),
            'credit' => $query->where('type',TransactionType::Credit->value),
            'credit_repayment' => $query->where('type',TransactionType::CreditRepayment->value),
            'withdrawal' => $query->where('type',TransactionType::Withdrawal->value),
            'commission' => $query->where('type',TransactionType::Commission->value),
            default => null,
        };

        return [$query,$range,$brandId,$kind,$preset,$from,$to];
    }

    public function index(
        Request $request,
        DashboardService $dashboard,
        CreditLedgerService $credits,
        SettingsService $settings
    ): View {
        [$query,$range,$brandId,$kind,$preset,$from,$to] = $this->filtered($request);

        $outstanding = collect();
        if ($kind === 'credit') {
            $ids = Agent::query()
                ->when($brandId,fn($q)=>$q->where('brand_id',$brandId))
                ->pluck('id');

            $map = $credits->outstandingMap($ids);

            $outstanding = Agent::with('brand')
                ->whereIn('id',array_keys(array_filter($map,fn($value)=>$value>0)))
                ->get()
                ->map(function(Agent $agent) use($map) {
                    $agent->outstanding_credit = $map[$agent->id] ?? 0;
                    return $agent;
                })
                ->sortByDesc('outstanding_credit')
                ->values();
        }

        $agentBreakdown = Transaction::query()
            ->selectRaw(
                "agent_id, brand_id,
                 COUNT(*) as transaction_count,
                 SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as deposit_total,
                 SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as credit_total,
                 SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as repayment_total,
                 SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as withdrawal_total,
                 SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as commission_total",
                [
                    TransactionType::PaidTopup->value,
                    TransactionType::Credit->value,
                    TransactionType::CreditRepayment->value,
                    TransactionType::Withdrawal->value,
                    TransactionType::Commission->value,
                ]
            )
            ->with(['agent','brand'])
            ->where('status',TransactionStatus::Completed->value)
            ->whereNotNull('agent_id')
            ->whereBetween('completed_at',[$range->start,$range->end])
            ->when($brandId,fn($q)=>$q->where('brand_id',$brandId))
            ->groupBy('agent_id','brand_id')
            ->orderByDesc('deposit_total')
            ->limit(100)
            ->get();

        $paymentBase = PaymentRecord::query()
            ->whereHas('transaction', function(Builder $tx) use($range,$brandId) {
                $tx->whereBetween('created_at',[$range->start,$range->end]);
                if ($brandId) $tx->where('brand_id',$brandId);
            });

        $duplicatePayments = (clone $paymentBase)
            ->with(['transaction.agent','transaction.brand','duplicateOf.transaction'])
            ->where('rejection_code','duplicate_transaction_id')
            ->latest()
            ->limit(12)
            ->get();

        $rejectedPaymentsCount = (clone $paymentBase)
            ->where('internal_status','rejected')
            ->count();

        $timeFlags = (clone $paymentBase)
            ->with(['transaction.agent','transaction.brand','fromBank','toBank'])
            ->whereIn('risk_level',[
                RiskLevel::Warning->value,
                RiskLevel::Serious->value,
                RiskLevel::Alarming->value,
                RiskLevel::Critical->value,
            ])
            ->latest('transaction_at')
            ->limit(12)
            ->get();

        $evidenceBase = EvidenceFile::query()
            ->whereHas('transaction', function(Builder $tx) use($range,$brandId) {
                $tx->whereBetween('created_at',[$range->start,$range->end]);
                if ($brandId) $tx->where('brand_id',$brandId);
            });

        $autoAccept = $settings->float('ai.auto_accept_confidence',0.90);

        $aiEscalations = (clone $evidenceBase)
            ->with(['transaction.agent','transaction.brand'])
            ->where(function(Builder $q) use($autoAccept) {
                $q->whereIn('status',[
                    EvidenceStatus::NeedsReupload->value,
                    EvidenceStatus::PendingEmployeeConfirmation->value,
                    EvidenceStatus::PendingAdminExtraction->value,
                    EvidenceStatus::Failed->value,
                ])->orWhere(function(Builder $confidence) use($autoAccept) {
                    $confidence->whereNotNull('critical_confidence')
                        ->where('critical_confidence','<',$autoAccept);
                });
            })
            ->latest()
            ->limit(12)
            ->get();

        return view('admin.reports.index',[
            'transactions'=>$query->latest('completed_at')->paginate(50)->withQueryString(),
            'summary'=>$dashboard->data($range,$brandId),
            'range'=>$range,
            'brandId'=>$brandId,
            'brands'=>Brand::orderBy('name')->get(),
            'preset'=>$preset,
            'from'=>$from,
            'to'=>$to,
            'kind'=>$kind,
            'outstandingCredits'=>$outstanding,
            'agentBreakdown'=>$agentBreakdown,
            'exceptionSummary'=>[
                'duplicates'=>$duplicatePayments->count(),
                'rejected_payments'=>$rejectedPaymentsCount,
                'time_flags'=>(clone $paymentBase)->whereIn('risk_level',[
                    RiskLevel::Warning->value,
                    RiskLevel::Serious->value,
                    RiskLevel::Alarming->value,
                    RiskLevel::Critical->value,
                ])->count(),
                'ai_escalations'=>(clone $evidenceBase)->where(function(Builder $q) use($autoAccept) {
                    $q->whereIn('status',[
                        EvidenceStatus::NeedsReupload->value,
                        EvidenceStatus::PendingEmployeeConfirmation->value,
                        EvidenceStatus::PendingAdminExtraction->value,
                        EvidenceStatus::Failed->value,
                    ])->orWhere(function(Builder $confidence) use($autoAccept) {
                        $confidence->whereNotNull('critical_confidence')
                            ->where('critical_confidence','<',$autoAccept);
                    });
                })->count(),
            ],
            'duplicatePayments'=>$duplicatePayments,
            'timeFlags'=>$timeFlags,
            'aiEscalations'=>$aiEscalations,
        ]);
    }

    public function export(Request $request)
    {
        [$query] = $this->filtered($request);
        $rows = $query->latest('completed_at')->get();

        return Response::streamDownload(function() use($rows) {
            $handle = fopen('php://output','w');
            fputcsv($handle,[
                'Reference','Type','Brand','Agent ID','Username','Employee',
                'Amount','Valid Payments','Risk','Completed'
            ]);

            foreach($rows as $transaction) {
                fputcsv($handle,[
                    $transaction->reference,
                    $transaction->type->value,
                    $transaction->brand?->name,
                    $transaction->agent?->agent_id,
                    $transaction->agent?->username,
                    $transaction->employee?->name,
                    $transaction->amount,
                    $transaction->valid_payment_total,
                    $transaction->risk_level?->value,
                    $transaction->completed_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        },'agent-audit-report.csv',['Content-Type'=>'text/csv']);
    }
}
