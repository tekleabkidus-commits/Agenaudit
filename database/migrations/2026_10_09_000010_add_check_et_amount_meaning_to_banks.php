<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            // Unknown until the operator has compared real official responses
            // against known receipts and the bank's documented fee behaviour.
            $table->string('check_et_amount_meaning', 32)
                ->default('unknown');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn('check_et_amount_meaning');
        });
    }
};
