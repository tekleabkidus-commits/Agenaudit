<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->string('check_et_account_source', 32)->default('none')->after('check_et_requires_account');
        });

        $now = now();

        $providers = [
            [
                'code' => 'CBE',
                'name' => 'Commercial Bank of Ethiopia',
                'aliases' => ['CBE', 'Commercial Bank of Ethiopia'],
                'check_et_code' => 'cbe',
                'check_et_requires_account' => true,
                'check_et_account_source' => 'receiving_account',
            ],
            [
                'code' => 'TELEBIRR',
                'name' => 'Telebirr',
                'aliases' => ['Telebirr'],
                'check_et_code' => 'telebirr',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'DASHEN',
                'name' => 'Dashen Bank',
                'aliases' => ['Dashen', 'Dashen Bank'],
                'check_et_code' => 'dashen',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'AWASH',
                'name' => 'Awash Bank',
                'aliases' => ['Awash', 'Awash Bank', 'AwashPay'],
                'check_et_code' => 'awash',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'BOA',
                'name' => 'Bank of Abyssinia',
                'aliases' => ['BOA', 'Bank of Abyssinia', 'Abyssinia'],
                'check_et_code' => 'boa',
                'check_et_requires_account' => true,
                'check_et_account_source' => 'receiving_account',
            ],
            [
                'code' => 'ZEMEN',
                'name' => 'Zemen Bank',
                'aliases' => ['Zemen', 'Zemen Bank'],
                'check_et_code' => 'zemen',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'CBEBIRR',
                'name' => 'CBE Birr',
                'aliases' => ['CBE Birr', 'CBEBirr', 'CBE-Birr'],
                'check_et_code' => 'cbebirr',
                'check_et_requires_account' => true,
                'check_et_account_source' => 'sender_account',
            ],
            [
                'code' => 'MPESA',
                'name' => 'M-Pesa Ethiopia',
                'aliases' => ['M-Pesa', 'M-Pesa Ethiopia', 'MPESA'],
                'check_et_code' => 'mpesa',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'SIINQEE',
                'name' => 'Siinqee Bank',
                'aliases' => ['Siinqee', 'Siinqee Bank'],
                'check_et_code' => 'siinqee',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
            [
                'code' => 'AMHARA',
                'name' => 'Amhara Bank',
                'aliases' => ['Amhara', 'Amhara Bank'],
                'check_et_code' => 'amhara',
                'check_et_requires_account' => false,
                'check_et_account_source' => 'none',
            ],
        ];

        foreach ($providers as $provider) {
            DB::table('banks')->updateOrInsert(
                ['code' => $provider['code']],
                [
                    'name' => $provider['name'],
                    'aliases' => json_encode($provider['aliases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'check_et_code' => $provider['check_et_code'],
                    'check_et_enabled' => true,
                    'check_et_requires_account' => $provider['check_et_requires_account'],
                    'check_et_account_source' => $provider['check_et_account_source'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn('check_et_account_source');
        });
    }
};
