<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_major_admin_pages_render(): void
    {
        $admin = User::create([
            'name'=>'Review Admin',
            'username'=>'review_admin',
            'password'=>'password_for_test_12345',
            'role'=>UserRole::Admin,
            'is_active'=>true,
        ]);
        $this->actingAs($admin);

        foreach ([
            'admin.dashboard',
            'admin.transactions.index',
            'admin.corrections.index',
            'admin.credits.index',
            'admin.commissions.index',
            'admin.reports.index',
            'admin.brands.index',
            'admin.agents.index',
            'admin.agent-imports.index',
            'admin.banks.index',
            'admin.receiving-accounts.index',
            'admin.employees.index',
            'admin.sessions.index',
            'admin.audit.index',
            'admin.settings.edit',
            'profile.edit',
        ] as $routeName) {
            $this->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_employee_mobile_pages_render_without_company_totals(): void
    {
        $brand = Brand::create(['name'=>'Assigned','slug'=>'assigned','is_active'=>true]);
        $employee = User::create([
            'name'=>'Review Employee',
            'username'=>'review_employee',
            'password'=>'password_for_test_12345',
            'role'=>UserRole::Employee,
            'is_active'=>true,
        ]);
        $employee->brands()->attach($brand);

        $this->actingAs($employee);
        $this->get(route('employee.home'))
            ->assertOk()
            ->assertDontSee('TOTAL DEPOSIT');
        $this->get(route('employee.transactions.create'))->assertOk();
        $this->get(route('employee.transactions.index'))->assertOk();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }
}
