<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->json('aliases')->nullable();
            $table->string('check_et_code')->nullable();
            $table->boolean('check_et_enabled')->default(true);
            $table->boolean('check_et_requires_account')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('agent_id');
            $table->string('agent_id_normalized')->unique();
            $table->string('username');
            $table->string('username_normalized')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('credit_enabled')->default(true);
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->boolean('commission_enabled')->default(false)->index();
            $table->unsignedSmallInteger('commission_monthly_limit')->default(2);
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('agent_brand_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('to_brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('changed_at');
            $table->timestampsTz();
        });

        Schema::create('receiving_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->string('account_number');
            $table->string('normalized_account_number');
            $table->string('account_name');
            $table->string('normalized_account_name');
            $table->json('name_aliases')->nullable();
            $table->string('account_type', 32)->default('bank');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->unique(['bank_id', 'normalized_account_number']);
        });

        Schema::create('brand_receiving_account', function (Blueprint $table) {
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receiving_account_id')->constrained()->cascadeOnDelete();
            $table->primary(['brand_id', 'receiving_account_id']);
        });

        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('correction_fields')->nullable();
            $table->timestampsTz();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->string('group')->nullable()->index();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device_label')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('employee_permissions');
        Schema::dropIfExists('brand_receiving_account');
        Schema::dropIfExists('receiving_accounts');
        Schema::dropIfExists('agent_brand_histories');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('brands');
    }
};
