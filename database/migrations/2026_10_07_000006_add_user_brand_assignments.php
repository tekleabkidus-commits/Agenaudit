<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_user', function (Blueprint $table) {
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['brand_id', 'user_id']);
            $table->index(['user_id', 'brand_id']);
        });

        // Preserve current production behavior for existing employees:
        // assign them to all currently active brands. Admin can narrow them afterward.
        $brandIds = DB::table('brands')->where('is_active', true)->pluck('id');
        $employeeIds = DB::table('users')->where('role', UserRole::Employee->value)->pluck('id');
        $now = now();

        foreach ($employeeIds as $userId) {
            foreach ($brandIds as $brandId) {
                DB::table('brand_user')->insertOrIgnore([
                    'brand_id' => $brandId,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_user');
    }
};
