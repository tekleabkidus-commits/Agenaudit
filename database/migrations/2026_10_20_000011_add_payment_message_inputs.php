<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->string('input_type',24);
            // Encrypted by Eloquent; do not expose in logs or responses.
            $table->longText('input_content');
            $table->string('reference')->nullable();
            $table->string('normalized_reference')->nullable()->index();
            $table->string('status',32)->default('pending')->index();
            $table->string('failure_code',64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('payment_record_id')->nullable()->unique();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('payment_records', function (Blueprint $table) {
            $table->unsignedBigInteger('evidence_file_id')->nullable()->change();
            $table->foreignId('payment_input_id')->nullable()->unique()->constrained('payment_inputs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_input_id');
        });
        Schema::dropIfExists('payment_inputs');
        // Existing message-based records have no screenshot, so do not force
        // evidence_file_id to NOT NULL during rollback.
    }
};
