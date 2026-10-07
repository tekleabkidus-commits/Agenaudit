<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedSmallInteger('credit_due_days')->nullable()->after('credit_limit');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('agent_balance_before', 18, 2)->nullable()->after('amount');
            $table->decimal('agent_balance_after', 18, 2)->nullable()->after('agent_balance_before');
            $table->string('agent_system_reference')->nullable()->after('agent_system_at');
            $table->string('withdrawal_reason_code', 64)->nullable()->after('reason');
            $table->text('withdrawal_reason_note')->nullable()->after('withdrawal_reason_code');
        });

        Schema::table('evidence_files', function (Blueprint $table) {
            $table->foreignId('employee_confirmed_by')->nullable()->after('critical_confidence')->constrained('users')->nullOnDelete();
            $table->timestampTz('employee_confirmed_at')->nullable()->after('employee_confirmed_by');
        });

        Schema::table('payment_records', function (Blueprint $table) {
            $table->foreignId('duplicate_of_payment_id')->nullable()->after('normalized_transaction_id')->constrained('payment_records')->nullOnDelete();
        });

        Schema::create('credit_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->foreignId('issue_transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->decimal('original_amount', 18, 2);
            $table->decimal('repaid_amount', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2);
            $table->string('status', 24)->default('unpaid')->index();
            $table->timestampTz('issued_at')->index();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('paid_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['agent_id','status']);
        });

        Schema::create('credit_repayment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('repayment_transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestampTz('allocated_at');
            $table->timestampsTz();
            $table->unique(['credit_record_id','repayment_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_repayment_allocations');
        Schema::dropIfExists('credit_records');

        Schema::table('payment_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_payment_id');
        });

        Schema::table('evidence_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_confirmed_by');
            $table->dropColumn('employee_confirmed_at');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'agent_balance_before',
                'agent_balance_after',
                'agent_system_reference',
                'withdrawal_reason_code',
                'withdrawal_reason_note',
            ]);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('credit_due_days');
        });
    }
};
