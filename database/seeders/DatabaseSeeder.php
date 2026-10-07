<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\EmployeePermission;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(SettingsService::class)->seedDefaults();

        if (app()->environment('production')) {
            return;
        }

        $adminUsername = strtolower((string) env('SEED_ADMIN_USERNAME', 'admin'));
        $adminPassword = (string) env('SEED_ADMIN_PASSWORD', 'ChangeMe!123');
        $employeeUsername = strtolower((string) env('SEED_EMPLOYEE_USERNAME', 'employee'));
        $employeePassword = (string) env('SEED_EMPLOYEE_PASSWORD', 'ChangeMe!123');

        $admin = User::firstOrCreate(
            ['username' => $adminUsername],
            ['name' => 'Platform Admin', 'password' => $adminPassword, 'role' => UserRole::Admin, 'is_active' => true]
        );
        $employee = User::firstOrCreate(
            ['username' => $employeeUsername],
            ['name' => 'Demo Employee', 'password' => $employeePassword, 'role' => UserRole::Employee, 'is_active' => true]
        );
        EmployeePermission::firstOrCreate(['user_id' => $employee->id], ['correction_fields' => []]);

        foreach (['Brand 1', 'Brand 2'] as $name) {
            Brand::firstOrCreate(['name' => $name], ['slug' => str($name)->slug()->toString(), 'is_active' => true]);
        }

        foreach ([
            ['CBE', 'Commercial Bank of Ethiopia', 'cbe', true, 'receiving_account'],
            ['TELEBIRR', 'Telebirr', 'telebirr', false, 'none'],
            ['DASHEN', 'Dashen Bank', 'dashen', false, 'none'],
            ['AWASH', 'Awash Bank', 'awash', false, 'none'],
            ['BOA', 'Bank of Abyssinia', 'boa', true, 'receiving_account'],
            ['ZEMEN', 'Zemen Bank', 'zemen', false, 'none'],
            ['CBEBIRR', 'CBE Birr', 'cbebirr', true, 'sender_account'],
            ['MPESA', 'M-Pesa Ethiopia', 'mpesa', false, 'none'],
            ['SIINQEE', 'Siinqee Bank', 'siinqee', false, 'none'],
            ['AMHARA', 'Amhara Bank', 'amhara', false, 'none'],
        ] as [$code, $name, $checkEtCode, $requiresAccount, $accountSource]) {
            Bank::firstOrCreate(['code' => $code], [
                'name' => $name,
                'aliases' => [$code, $name],
                'check_et_code' => $checkEtCode,
                'check_et_enabled' => true,
                'check_et_requires_account' => $requiresAccount,
                'check_et_account_source' => $accountSource,
                'is_active' => true,
            ]);
        }
        }
    }
}
