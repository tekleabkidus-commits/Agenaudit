<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('agent_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->string('status', 50)->index();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('valid_payment_total', 18, 2)->default(0);
            $table->decimal('bank_payment_total', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->decimal('outstanding_credit_at_time', 18, 2)->nullable();
            $table->timestampTz('agent_system_at')->nullable();
            $table->string('risk_level', 32)->nullable()->index();
            $table->string('external_verification_status', 32)->nullable()->index();
            $table->text('reason')->nullable();
            $table->text('review_reason')->nullable();
            $table->string('rejection_code')->nullable()->index();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampTz('rejected_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['brand_id', 'completed_at']);
            $table->index(['employee_id', 'created_at']);
        });

        Schema::create('evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('evidence_files')->nullOnDelete();
            $table->string('kind', 32)->index();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();
            $table->string('status', 40)->index();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->decimal('critical_confidence', 5, 4)->nullable();
            $table->json('extracted')->nullable();
            $table->json('raw_ai_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
            $table->index(['transaction_id', 'kind']);
        });

        Schema::create('payment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evidence_file_id')->unique()->constrained('evidence_files')->cascadeOnDelete();
            $table->foreignId('from_bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->foreignId('to_bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->foreignId('receiving_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_bank_raw')->nullable();
            $table->string('to_bank_raw')->nullable();
            $table->string('sender_account')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('receiver_account')->nullable();
            $table->string('receiver_name')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('transaction_id_raw')->nullable();
            $table->string('normalized_transaction_id')->nullable()->unique();
            $table->timestampTz('transaction_at')->nullable();
            $table->string('account_match_method')->nullable();
            $table->decimal('account_match_confidence', 5, 4)->nullable();
            $table->string('internal_status', 32)->index();
            $table->string('external_status', 32)->index();
            $table->json('external_response')->nullable();
            $table->json('external_request_keys')->nullable();
            $table->timestampTz('external_checked_at')->nullable();
            $table->string('risk_level', 32)->nullable()->index();
            $table->integer('time_difference_minutes')->nullable();
            $table->string('rejection_code')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
        });

        Schema::create('transaction_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 64);
            $table->json('metadata')->nullable();
            $table->timestampTz('confirmed_at');
            $table->unique(['transaction_id', 'user_id', 'kind']);
        });

        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64)->index();
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('entry_type', 32)->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
            $table->index(['agent_id', 'occurred_at']);
        });

        Schema::create('correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('evidence_file_id')->nullable()->constrained('evidence_files')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('field_name', 64);
            $table->json('ai_value')->nullable();
            $table->json('proposed_value')->nullable();
            $table->text('reason');
            $table->string('status', 32)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_requests');
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('transaction_events');
        Schema::dropIfExists('transaction_confirmations');
        Schema::dropIfExists('payment_records');
        Schema::dropIfExists('evidence_files');
        Schema::dropIfExists('transactions');
    }
};
