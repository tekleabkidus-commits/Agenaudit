<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('commission_enabled')->default(false);
            $table->unsignedSmallInteger('commission_monthly_limit')->default(2);
        });

        // Preserve existing commission eligibility: brands that already have
        // enabled agents must not be switched off during this migration.
        $activeBrandIds = DB::table('agents')
            ->where('commission_enabled', true)
            ->distinct()
            ->pluck('brand_id');

        if ($activeBrandIds->isNotEmpty()) {
            DB::table('brands')->whereIn('id', $activeBrandIds)
                ->update(['commission_enabled'=>true]);
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['commission_enabled', 'commission_monthly_limit']);
        });
    }
};
