<?php

namespace Tests\Feature;

use App\Enums\CreditEntryType;
use App\Enums\EvidenceKind;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Jobs\ProcessEvidenceJob;
use App\Models\Agent;
use App\Services\AI\VisionExtractorInterface;
use App\Services\Transactions\EvidenceProcessor;
use App\Services\Transactions\EvidenceStorageService;
use App\Services\SettingsService;
use Mockery;
use App\Models\Brand;
use App\Models\CreditLedgerEntry;
use App\Models\EvidenceFile;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceFirstTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function employee(Brand $brand): User
    {
        $user = User::create([
            'name'=>'Test Employee',
            'username'=>'testemployee',
            'password'=>'correct-horse-staple',
            'role'=>UserRole::Employee,
            'is_active'=>true,
        ]);

        $user->brands()->sync([$brand->id]);
        return $user;
    }

    private function brand(string $name='Kemer'): Brand
    {
        return Brand::create([
            'name'=>$name,
            'slug'=>Str::slug($name),
            'is_active'=>true,
        ]);
    }

    private function agent(Brand $brand): Agent
    {
        return Agent::create([
            'brand_id'=>$brand->id,
            'agent_id'=>'A-123',
            'agent_id_normalized'=>\App\Support\Normalizer::identifier('A-123'),
            'username'=>'testagent',
            'username_normalized'=>'TESTAGENT',
            'is_active'=>true,
            'credit_enabled'=>true,
            'commission_enabled'=>false,
            'commission_monthly_limit'=>2,
        ]);
    }

    private function outstandingCredit(Agent $agent, User $user): void
    {
        $tx = Transaction::create([
            'reference'=>(string) Str::uuid(),
            'agent_id'=>$agent->id,
            'brand_id'=>$agent->brand_id,
            'employee_id'=>$user->id,
            'type'=>TransactionType::Credit,
            'status'=>TransactionStatus::Completed,
            'amount'=>150,
        ]);

        CreditLedgerEntry::create([
            'agent_id'=>$agent->id,
            'transaction_id'=>$tx->id,
            'entry_type'=>CreditEntryType::Issue,
            'amount'=>150,
            'balance_before'=>0,
            'balance_after'=>150,
            'occurred_at'=>now(),
        ]);
    }

    public function test_opening_transaction_type_and_evidence_pages_creates_no_draft(): void
    {
        $employee = $this->employee($this->brand());

        $this->actingAs($employee)->get(route('employee.transactions.create'))->assertOk();
        $this->actingAs($employee)->get(route('employee.transactions.start',['type'=>'paid_topup']))->assertOk();
        $this->actingAs($employee)->get(route('employee.transactions.start',['type'=>'credit']))->assertOk();
        $this->actingAs($employee)->get(route('employee.transactions.start',['type'=>'commission']))->assertOk();
        $this->actingAs($employee)->get(route('employee.transactions.start',['type'=>'withdrawal']))->assertOk();
        $this->assertDatabaseCount('transactions', 0);

        // The old form endpoint cannot create an empty transaction anymore.
        $this->actingAs($employee)->post('/employee/transactions', ['type'=>'paid_topup'])
            ->assertStatus(405);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_missing_first_screenshot_does_not_persist_transaction(): void
    {
        $employee=$this->employee($this->brand());
        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'paid_topup']),
            []
        )->assertSessionHasErrors('screenshots');

        $this->assertDatabaseCount('transactions',0);
        $this->assertDatabaseCount('evidence_files',0);
    }

    public function test_first_agent_screenshot_creates_processing_record_not_draft(): void
    {
        Storage::fake('private');
        Queue::fake();
        $employee=$this->employee($this->brand());

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'credit']),
            ['screenshots'=>[UploadedFile::fake()->image('agent.png')]]
        )->assertRedirect();

        $this->assertDatabaseCount('transactions',1);
        $transaction=Transaction::firstOrFail();
        $this->assertSame(TransactionStatus::Processing,$transaction->status);
        $this->assertSame(TransactionType::Credit,$transaction->type);
        $this->assertDatabaseCount('evidence_files',1);
        $this->assertSame(EvidenceKind::AgentSystem,EvidenceFile::firstOrFail()->kind);
        Queue::assertPushed(ProcessEvidenceJob::class, 1);
        $this->assertDatabaseMissing('transactions',['status'=>TransactionStatus::Draft->value]);
    }

    public function test_repayment_requires_agent_selection_and_evidence_before_creation(): void
    {
        Storage::fake('private');
        Queue::fake();
        $brand=$this->brand();
        $employee=$this->employee($brand);
        $agent=$this->agent($brand);
        $this->outstandingCredit($agent,$employee);

        $before=Transaction::count();
        $this->actingAs($employee)->get(
            route('employee.transactions.start',['type'=>'credit_repayment'])
        )->assertOk()->assertSee($agent->agent_id);
        $this->actingAs($employee)->get(
            route('employee.transactions.repayment.evidence',['agent'=>$agent])
        )->assertOk();
        $this->assertSame($before,Transaction::count());

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'credit_repayment']),
            ['repayment_agent_id'=>$agent->id]
        )->assertSessionHasErrors('screenshots');
        $this->assertSame($before,Transaction::count());

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'credit_repayment']),
            [
                'repayment_agent_id'=>$agent->id,
                'screenshots'=>[
                    UploadedFile::fake()->image('receipt-1.png'),
                    UploadedFile::fake()->image('receipt-2.png'),
                ],
            ]
        )->assertRedirect();

        $this->assertDatabaseCount('transactions',$before+1);
        $repayment=Transaction::where('type',TransactionType::CreditRepayment->value)->firstOrFail();
        $this->assertSame(TransactionStatus::Processing,$repayment->status);
        $this->assertSame($agent->id,$repayment->agent_id);
        $this->assertSame($brand->id,$repayment->brand_id);
        $this->assertSame(2,$repayment->evidenceFiles()->count());
        Queue::assertPushed(ProcessEvidenceJob::class,2);
    }

    public function test_employee_cannot_select_repayment_agent_in_another_brand(): void
    {
        $employee=$this->employee($this->brand('Kemer'));
        $otherBrand=$this->brand('Betna');
        $agent=$this->agent($otherBrand);

        $this->actingAs($employee)->get(
            route('employee.transactions.repayment.evidence',['agent'=>$agent])
        )->assertForbidden();

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'credit_repayment']),
            [
                'repayment_agent_id'=>$agent->id,
                'screenshots'=>[UploadedFile::fake()->image('receipt.png')],
            ]
        )->assertForbidden();

        $this->assertDatabaseCount('transactions',0);
    }
    public function test_multiple_agent_proof_images_create_one_transaction_and_one_processing_job(): void
    {
        Storage::fake('private');
        Queue::fake();

        $employee=$this->employee($this->brand());

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'paid_topup']),
            ['screenshots'=>[
                UploadedFile::fake()->image('identity.png'),
                UploadedFile::fake()->image('balance.png'),
                UploadedFile::fake()->image('confirmation.png'),
            ]]
        )->assertRedirect();

        $this->assertDatabaseCount('transactions',1);
        $this->assertDatabaseCount('evidence_files',3);
        $transaction=Transaction::firstOrFail();
        $this->assertSame(TransactionStatus::Processing,$transaction->status);
        $this->assertSame(3,$transaction->evidenceFiles()->where('kind',EvidenceKind::AgentSystem->value)->count());
        Queue::assertPushed(ProcessEvidenceJob::class,1);
    }

    public function test_agent_proof_limit_is_enforced_without_creating_empty_transactions(): void
    {
        $employee=$this->employee($this->brand());

        $files=[];
        for($i=0;$i<6;$i++) $files[]=UploadedFile::fake()->image("proof-{$i}.png");

        $this->actingAs($employee)->post(
            route('employee.transactions.initial-evidence.store',['type'=>'credit']),
            ['screenshots'=>$files]
        )->assertSessionHasErrors('screenshots');

        $this->assertDatabaseCount('transactions',0);
        $this->assertDatabaseCount('evidence_files',0);
    }

    public function test_two_agent_proofs_are_read_together_and_do_not_double_count_amount(): void
    {
        Storage::fake('private');
        $brand=$this->brand();
        $employee=$this->employee($brand);
        $agent=$this->agent($brand);

        $transaction=Transaction::create([
            'reference'=>(string) Str::uuid(),
            'employee_id'=>$employee->id,
            'type'=>TransactionType::Credit,
            'status'=>TransactionStatus::Processing,
        ]);

        $store=app(EvidenceStorageService::class);
        $first=$store->store($transaction,UploadedFile::fake()->image('first.png'),EvidenceKind::AgentSystem);
        $store->store($transaction,UploadedFile::fake()->image('second.png'),EvidenceKind::AgentSystem);

        app(SettingsService::class)->set('ai.enabled',true);

        $mock=Mockery::mock(VisionExtractorInterface::class);
        $mock->shouldReceive('extractMany')->once()
            ->withArgs(fn ($group) => count($group)===2)
            ->andReturn([
                'quality'=>['score'=>.99,'critical_confidence'=>.99,'issues'=>[]],
                'agent_id'=>$agent->agent_id,
                'agent_username'=>$agent->username,
                'amount'=>150,
                'transaction_at'=>now()->toIso8601String(),
                'balance_before'=>100,
                'balance_after'=>250,
                'transaction_reference'=>'SAME-EVENT-123',
                'brand_hint'=>$brand->name,
            ]);
        $this->app->instance(VisionExtractorInterface::class,$mock);

        app(EvidenceProcessor::class)->process($first);
        $transaction->refresh();

        $this->assertSame($agent->id,$transaction->agent_id,$transaction->review_reason ?: $transaction->rejection_reason ?: 'No review reason');
        $this->assertEquals(150,$transaction->amount);
        $this->assertSame(TransactionStatus::ReadyForReview,$transaction->status);
        $this->assertSame(2,$transaction->evidenceFiles()
            ->where('status',\App\Enums\EvidenceStatus::Extracted->value)->count());
    }

}
