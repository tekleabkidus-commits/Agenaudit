<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $entries = DB::table('credit_ledger_entries')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            if ($entry->entry_type === 'issue') {
                if (DB::table('credit_records')->where('issue_transaction_id',$entry->transaction_id)->exists()) {
                    continue;
                }

                $agent = DB::table('agents')->where('id',$entry->agent_id)->first();
                $dueDays = $agent?->credit_due_days ?? 7;
                $issuedAt = $entry->occurred_at;

                DB::table('credit_records')->insert([
                    'agent_id'=>$entry->agent_id,
                    'issue_transaction_id'=>$entry->transaction_id,
                    'original_amount'=>$entry->amount,
                    'repaid_amount'=>0,
                    'outstanding_amount'=>$entry->amount,
                    'status'=>'unpaid',
                    'issued_at'=>$issuedAt,
                    'due_at'=>$dueDays > 0 ? \Carbon\CarbonImmutable::parse($issuedAt)->addDays($dueDays) : null,
                    'paid_at'=>null,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                continue;
            }

            if ($entry->entry_type !== 'repayment') {
                continue;
            }

            $remaining = round((float)$entry->amount,2);

            $records = DB::table('credit_records')
                ->where('agent_id',$entry->agent_id)
                ->whereIn('status',['unpaid','partial'])
                ->where('outstanding_amount','>',0)
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderBy('issued_at')
                ->get();

            foreach ($records as $record) {
                if ($remaining <= 0) break;

                $apply = min($remaining,(float)$record->outstanding_amount);
                if ($apply <= 0) continue;

                DB::table('credit_repayment_allocations')->updateOrInsert(
                    [
                        'credit_record_id'=>$record->id,
                        'repayment_transaction_id'=>$entry->transaction_id,
                    ],
                    [
                        'amount'=>$apply,
                        'allocated_at'=>$entry->occurred_at,
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ]
                );

                $repaid = round((float)$record->repaid_amount + $apply,2);
                $outstanding = max(0,round((float)$record->original_amount - $repaid,2));
                $paid = $outstanding <= 0.009;

                DB::table('credit_records')->where('id',$record->id)->update([
                    'repaid_amount'=>$repaid,
                    'outstanding_amount'=>$outstanding,
                    'status'=>$paid ? 'paid' : 'partial',
                    'paid_at'=>$paid ? $entry->occurred_at : null,
                    'updated_at'=>now(),
                ]);

                $remaining = round($remaining-$apply,2);
            }
        }
    }

    public function down(): void
    {
        // Backfilled rows are valid domain records. Do not destructively remove
        // them when rolling back this data migration.
    }
};
