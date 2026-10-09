<?php

namespace Tests\Feature;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\HardRejectException;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\AgentIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandCommissionAndHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private function brand(string $name): Brand
    {
        return Brand::create(['name'=>$name, 'slug'=>Str::slug($name), 'is_active'=>true]);
    }

    private function user(UserRole $role, string $username): User
    {
        return User::create([
            'name'=>$username,
            'username'=>$username,
            'role'=>$role,
            'password'=>'this-is-a-test-password',
            'is_active'=>true,
        ]);
    }

    private function agent(Brand $brand, string $agentId): Agent
    {
        return Agent::create([
            'brand_id'=>$brand->id,
            'agent_id'=>$agentId,
            'agent_id_normalized'=>strtoupper($agentId),
            'username'=>'user'.strtolower($agentId),
            'username_normalized'=>'USER'.strtoupper($agentId),
            'commission_enabled'=>false,
            'commission_monthly_limit'=>2,
        ]);
    }

    private function transaction(User $employee, Agent $agent, TransactionStatus $status = TransactionStatus::Completed): Transaction
    {
        return Transaction::create([
            'reference'=>(string) Str::uuid(),
            'employee_id'=>$employee->id,
            'agent_id'=>$agent->id,
            'brand_id'=>$agent->brand_id,
            'type'=>TransactionType::PaidTopup,
            'status'=>$status,
        ]);
    }

    public function test_history_agent_filter_is_limited_to_own_visible_transactions(): void
    {
        $brand=$this->brand('Kemer');
        $other=$this->brand('Betna');
        $employee=$this->user(UserRole::Employee,'employee1');
        $employee->brands()->sync([$brand->id]);
        $otherEmployee=$this->user(UserRole::Employee,'employee2');
        $otherEmployee->brands()->sync([$brand->id]);

        $own=$this->agent($brand,'A1');
        $otherAgent=$this->agent($brand,'A2');
        $unassigned=$this->agent($other,'A3');

        $ownTx=$this->transaction($employee,$own);
        $this->transaction($otherEmployee,$otherAgent);
        $this->transaction($employee,$unassigned);

        $response=$this->actingAs($employee)->get(route('employee.transactions.index'));
        $response->assertOk();
        $response->assertSee('A1');
        $response->assertDontSee('<option value="'.$otherAgent->id.'"',false);
        $response->assertDontSee('<option value="'.$unassigned->id.'"',false);
        $response->assertSee('name="agent"',false);

        $this->actingAs($employee)->get(route('employee.transactions.index',['agent'=>$own->id]))
            ->assertOk()->assertSee(route('employee.transactions.show',$ownTx));
        $this->actingAs($employee)->get(route('employee.transactions.index',['agent'=>$otherAgent->id]))
            ->assertOk()->assertDontSee(route('employee.transactions.show',$ownTx));
    }

    public function test_old_empty_draft_is_not_shown_in_history_or_agent_filter(): void
    {
        $brand=$this->brand('Kemer');
        $employee=$this->user(UserRole::Employee,'employee1');
        $employee->brands()->sync([$brand->id]);
        $agent=$this->agent($brand,'A4');
        $draft=$this->transaction($employee,$agent,TransactionStatus::Draft);

        $response=$this->actingAs($employee)->get(route('employee.transactions.index'));
        $response->assertOk()->assertDontSee($draft->reference)->assertDontSee($agent->agent_id.' · '.$agent->username);
    }

    public function test_bulk_brand_commission_switch_updates_existing_agents_and_limits(): void
    {
        $brand=$this->brand('Kemer');
        $one=$this->agent($brand,'A1');
        $two=$this->agent($brand,'A2');
        $admin=$this->user(UserRole::Admin,'admin1');

        $this->actingAs($admin)->put(route('admin.commissions.brands.update',$brand),[
            'commission_enabled'=>'1',
            'commission_monthly_limit'=>5,
            'apply_to_agents'=>'1',
        ])->assertRedirect();

        $this->assertTrue($brand->fresh()->commission_enabled);
        $this->assertSame(5,$brand->fresh()->commission_monthly_limit);
        $this->assertTrue($one->fresh()->commission_enabled);
        $this->assertTrue($two->fresh()->commission_enabled);
        $this->assertSame(5,$one->fresh()->commission_monthly_limit);

        $this->actingAs($admin)->put(route('admin.commissions.brands.update',$brand),[
            'commission_monthly_limit'=>3,
            'apply_to_agents'=>'1',
        ])->assertRedirect();

        $this->assertFalse($brand->fresh()->commission_enabled);
        $this->assertFalse($one->fresh()->commission_enabled);
        $this->assertFalse($two->fresh()->commission_enabled);
        $this->assertSame(3,$two->fresh()->commission_monthly_limit);
    }

    public function test_brand_policy_can_change_without_overwriting_individual_agent_values(): void
    {
        $brand=$this->brand('Kemer');
        $agent=$this->agent($brand,'A1');
        $admin=$this->user(UserRole::Admin,'admin1');

        $this->actingAs($admin)->put(route('admin.commissions.brands.update',$brand),[
            'commission_enabled'=>'1',
            'commission_monthly_limit'=>7,
        ])->assertRedirect();

        $this->assertTrue($brand->fresh()->commission_enabled);
        $this->assertSame(7,$brand->fresh()->commission_monthly_limit);
        $this->assertFalse($agent->fresh()->commission_enabled);
        $this->assertSame(2,$agent->fresh()->commission_monthly_limit);
    }

    public function test_new_single_agent_inherits_brand_commission_defaults(): void
    {
        $brand=$this->brand('Kemer');
        $brand->update(['commission_enabled'=>true,'commission_monthly_limit'=>6]);
        $admin=$this->user(UserRole::Admin,'admin1');

        $this->actingAs($admin)->post(route('admin.agents.store'),[
            'brand_id'=>$brand->id,
            'agent_id'=>'NEW123',
            'username'=>'newuser123',
        ])->assertRedirect();

        $agent=Agent::where('agent_id','NEW123')->firstOrFail();
        $this->assertTrue($agent->commission_enabled);
        $this->assertSame(6,$agent->commission_monthly_limit);
    }

    public function test_brand_off_blocks_agent_commission_even_when_agent_switch_is_on(): void
    {
        $brand=$this->brand('Kemer');
        $employee=$this->user(UserRole::Employee,'employee1');
        $employee->brands()->sync([$brand->id]);
        $agent=$this->agent($brand,'A1');
        $agent->update(['commission_enabled'=>true]);

        $transaction=Transaction::create([
            'reference'=>(string) Str::uuid(),
            'employee_id'=>$employee->id,
            'agent_id'=>$agent->id,
            'brand_id'=>$brand->id,
            'type'=>TransactionType::Commission,
            'status'=>TransactionStatus::Processing,
        ]);

        $this->expectException(HardRejectException::class);
        app(AgentIdentityService::class)->apply($transaction,[
            'agent_id'=>'A1',
            'agent_username'=>'usera1',
            'amount'=>100,
            'transaction_at'=>now()->toIso8601String(),
        ]);
    }
}
