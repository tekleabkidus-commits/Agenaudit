<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('disk');
            $table->string('path');
            $table->string('status', 32)->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('new_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('move_rows')->default(0);
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('agent_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('brand_name')->nullable();
            $table->string('agent_id')->nullable();
            $table->string('agent_username')->nullable();
            $table->string('action', 32)->nullable();
            $table->string('status', 32)->index();
            $table->json('errors')->nullable();
            $table->foreignId('resolved_brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('resolved_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['agent_import_id', 'row_number']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('agent_import_rows');
        Schema::dropIfExists('agent_imports');
    }
};
