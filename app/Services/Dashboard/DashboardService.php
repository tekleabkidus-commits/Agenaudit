<?php

namespace App\Services\Dashboard;

use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

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

        $banks = $paymentQuery
            ->groupBy('banks.id','banks.name')
            ->orderByDesc('total')
            ->get();

        $bankRows = $banks->map(fn ($row) => [
            'bank'=>$row->bank_name ?: 'Unknown',
            'total'=>(float)$row->total,
            'percentage'=>$deposit > 0 ? round(((float)$row->total / $deposit) * 100, 1) : 0,
        ])->values();

        $dailyRaw = (clone $base)
            ->selectRaw('DATE(completed_at) as day, type, SUM(amount) as total')
            ->groupByRaw('DATE(completed_at), type')
            ->orderBy('day')
            ->get();

        $dailyGrouped = $dailyRaw->groupBy(fn($row)=>(string)$row->day);
        $days = [];
        $cursor = CarbonImmutable::parse($range->start)->startOfDay();
        $endDay = CarbonImmutable::parse($range->end)->startOfDay();

        while ($cursor->lte($endDay)) {
            $key = $cursor->toDateString();
            $rows = $dailyGrouped->get($key, collect())->keyBy('type');

            $days[] = [
                'date'=>$cursor,
                'deposit'=>(float) optional($rows->get(TransactionType::PaidTopup->value))->total,
                'credit'=>(float) optional($rows->get(TransactionType::Credit->value))->total,
                'withdrawal'=>(float) optional($rows->get(TransactionType::Withdrawal->value))->total,
                'commission'=>(float) optional($rows->get(TransactionType::Commission->value))->total,
            ];

            $cursor = $cursor->addDay();
        }

        $dailyMax = max(1, collect($days)->flatMap(fn($day)=>[
            $day['deposit'],$day['credit'],$day['withdrawal'],$day['commission']
        ])->max() ?: 1);

        $summary = collect(TransactionType::cases())->mapWithKeys(function(TransactionType $type) use ($base) {
            return [$type->value => [
                'label'=>$type->label(),
                'count'=>(clone $base)->where('type',$type->value)->count(),
                'amount'=>(float)(clone $base)->where('type',$type->value)->sum('amount'),
            ]];
        });

        $recent = (clone $base)
            ->with(['agent','brand','employee'])
            ->latest('completed_at')
            ->limit(8)
            ->get();

        return [
            'range'=>$range,
            'deposit'=>$deposit,
            'credit'=>$credit,
            'credit_repayment'=>$creditRepayment,
            'withdrawal'=>$withdrawal,
            'commission'=>$commission,
            'banks'=>$bankRows,
            'completed_count'=>(clone $base)->count(),
            'daily'=>$days,
            'daily_max'=>$dailyMax,
            'transaction_summary'=>$summary,
            'recent'=>$recent,
        ];
    }
}
