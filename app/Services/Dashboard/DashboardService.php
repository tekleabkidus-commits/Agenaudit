<?php

namespace App\Services\Dashboard;

use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class DashboardService
{
    public function data(DateRange $range, ?int $brandId): array
    {
        $base = Transaction::query()
            ->where('status', TransactionStatus::Completed->value)
            ->whereBetween('completed_at', [$range->start, $range->end]);
        if ($brandId) $base->where('brand_id', $brandId);

        $deposit = (float) (clone $base)->where('type',TransactionType::PaidTopup->value)->sum('amount');
        $credit = (float) (clone $base)->where('type',TransactionType::Credit->value)->sum('amount');
        $withdrawal = (float) (clone $base)->where('type',TransactionType::Withdrawal->value)->sum('amount');
        $creditRepayment = (float) (clone $base)->where('type',TransactionType::CreditRepayment->value)->sum('amount');
        $commission = (float) (clone $base)->where('type',TransactionType::Commission->value)->sum('amount');

        $paymentQuery = PaymentRecord::query()
            ->selectRaw('banks.id as bank_id, banks.name as bank_name, SUM(payment_records.amount) as total')
            ->join('transactions','transactions.id','=','payment_records.transaction_id')
            ->leftJoin('banks','banks.id','=','payment_records.to_bank_id')
            ->where('transactions.status',TransactionStatus::Completed->value)
            ->where('transactions.type',TransactionType::PaidTopup->value)
            ->where('payment_records.internal_status',PaymentValidationStatus::Valid->value)
            ->whereBetween('transactions.completed_at',[$range->start,$range->end]);
        if ($brandId) $paymentQuery->where('transactions.brand_id',$brandId);
        $banks = $paymentQuery->groupBy('banks.id','banks.name')->orderByDesc('total')->get();
        $bankRows = $banks->map(fn ($row) => [
            'bank'=>$row->bank_name ?: 'Unknown','total'=>(float)$row->total,
            'percentage'=>$deposit > 0 ? round(((float)$row->total / $deposit) * 100, 1) : 0,
        ])->values();

        return [
            'range'=>$range,'deposit'=>$deposit,'credit'=>$credit,'credit_repayment'=>$creditRepayment,'withdrawal'=>$withdrawal,'commission'=>$commission,
            'banks'=>$bankRows,'completed_count'=>(clone $base)->count(),
        ];
    }
}
