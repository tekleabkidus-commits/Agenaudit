<?php

namespace Tests\Feature;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\AgentTopupReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentTopupReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $brand=Brand::create(['name'=>'Reference Demo','slug'=>'reference-demo','is_active'=>true]);
        $employee=User::create([
            'name'=>'Reference Operator','username'=>'reference_operator',
            'password'=>'test-password-should-be-long','role'=>UserRole::Employee,'is_active'=>true,
        ]);
        $employee->brands()->attach($brand);
        $agent=Agent::create([
            'brand_id'=>$brand->id,'agent_id'=>'REF001','agent_id_normalized'=>'REF001',
            'username'=>'refagent','username_normalized'=>'REFAGENT','is_active'=>true,
        ]);
        $tx=Transaction::create([
            'reference'=>(string)Str::uuid(),'employee_id'=>$employee->id,
            'brand_id'=>$brand->id,'agent_id'=>$agent->id,'amount'=>30000,
            'type'=>TransactionType::PaidTopup,'status'=>TransactionStatus::Processing,
            'agent_system_at'=>now(),
        ]);
        return [$tx,$employee];
    }

    private function receipt(
        Transaction $tx,
        int $sequence,
        float $amount,
        PaymentValidationStatus $internal,
        ExternalVerificationStatus $external,
        bool $isDebit = false
    ): PaymentRecord {
        $extracted = [
            '_receipt_intelligence'=>[
                'amount_needs_review'=>$isDebit,
                'amount_role'=>$isDebit?'total_debit':'transfer_amount',
                'total_debited'=>$isDebit?$amount:null,
            ],
        ];

        $file=EvidenceFile::create([
            'transaction_id'=>$tx->id,
            'kind'=>EvidenceKind::BankPayment,
            'sequence'=>$sequence,
            'disk'=>'private','path'=>"tests/reference-{$sequence}.png",
            'original_name'=>'bank.png','mime_type'=>'image/png',
            'size_bytes'=>100,
            'sha256'=>hash('sha256',"test-reference-{$sequence}"),
            'status'=>EvidenceStatus::Extracted,
            'extracted'=>$extracted,
        ]);
        return PaymentRecord::create([
            'transaction_id'=>$tx->id,
            'evidence_file_id'=>$file->id,
            'amount'=>$amount,
            'internal_status'=>$internal,
            'external_status'=>$external,
            'transaction_id_raw'=>'FTREFERENCE'.str_pad((string)$sequence,5,'0',STR_PAD_LEFT),
            'normalized_transaction_id'=>'FTREFERENCE'.str_pad((string)$sequence,5,'0',STR_PAD_LEFT),
            'rejection_code'=>$internal===PaymentValidationStatus::Review?'transfer_amount_unconfirmed':null,
        ]);
    }

    public function test_agent_topup_is_reference_for_three_receipts_with_one_unknown_fee(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,10000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,8000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $unknown=$this->receipt($tx,3,12008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);
        $result=app(AgentTopupReferenceService::class)->analyze($tx);

        $this->assertSame(3000000,$result['target_cents']);
        $this->assertSame(1800000,$result['readable_principal_cents']);
        $this->assertSame(1800000,$result['check_et_confirmed_cents']);
        $this->assertSame(1200000,$result['remainder_cents']);
        $this->assertSame($unknown->id,$result['candidate']['payment_id']);
        $this->assertSame(1200000,$result['candidate']['candidate_principal_cents']);
        $this->assertSame(1200800,$result['candidate']['payer_debit_cents']);
        $this->assertSame(800,$result['candidate']['implied_charges_cents']);
        $this->assertTrue($result['candidate']['reference_only']);
        $this->assertEquals(12008,$unknown->fresh()->amount);
        $this->assertSame(PaymentValidationStatus::Review,$unknown->fresh()->internal_status);
        $this->assertSame(3,$tx->payments()->count());
    }

    public function test_check_et_outage_does_not_make_readable_receipt_bank_verified(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,10000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Unavailable);
        $this->receipt($tx,2,20000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Unavailable);
        $result=app(AgentTopupReferenceService::class)->analyze($tx);

        $this->assertSame(3000000,$result['readable_principal_cents']);
        $this->assertSame(0,$result['check_et_confirmed_cents']);
        $this->assertSame(0,$result['remainder_cents']);
        $this->assertNull($result['candidate']);
        $this->assertStringContainsString('may still be unverified',$result['explanation']);
    }

    public function test_two_unknown_receipts_never_get_individual_estimates(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,10000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,8008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);
        $this->receipt($tx,3,12008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);

        $result=app(AgentTopupReferenceService::class)->analyze($tx);
        $this->assertSame(2000000,$result['remainder_cents']);
        $this->assertSame(2,$result['uncertain_count']);
        $this->assertNull($result['candidate']);
    }

    public function test_debit_lower_than_missing_principal_is_not_accepted(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,10000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,19000,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);
        $result=app(AgentTopupReferenceService::class)->analyze($tx);

        $this->assertNull($result['candidate']);
        $this->assertStringContainsString('exceeds the visible payer debit',$result['explanation']);
    }

    public function test_unprocessed_screenshot_prevents_remaining_amount_allocation(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,18000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,12008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);
        EvidenceFile::create([
            'transaction_id'=>$tx->id,'kind'=>EvidenceKind::BankPayment,
            'sequence'=>3,'disk'=>'private','path'=>'tests/unprocessed.png',
            'original_name'=>'unprocessed.png','mime_type'=>'image/png',
            'size_bytes'=>100,'sha256'=>hash('sha256','test-unprocessed'),
            'status'=>EvidenceStatus::Queued,
        ]);

        $result=app(AgentTopupReferenceService::class)->analyze($tx);
        $this->assertSame(1,$result['open_evidence_count']);
        $this->assertNull($result['candidate']);
    }

    public function test_rejected_duplicate_prevents_suggestion_and_never_counts_it(): void
    {
        [$tx]=$this->fixture();
        $this->receipt($tx,1,18000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,12008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);
        $this->receipt($tx,3,1000,PaymentValidationStatus::Rejected,ExternalVerificationStatus::NotRequired);

        $result=app(AgentTopupReferenceService::class)->analyze($tx);
        $this->assertSame(1,$result['excluded_count']);
        $this->assertSame(1800000,$result['readable_principal_cents']);
        $this->assertNull($result['candidate']);
    }

    public function test_employee_and_admin_can_see_reference_arithmetic_but_not_a_verified_claim(): void
    {
        [$tx,$employee]=$this->fixture();
        $this->receipt($tx,1,18000,PaymentValidationStatus::Valid,ExternalVerificationStatus::Passed);
        $this->receipt($tx,2,12008,PaymentValidationStatus::Review,ExternalVerificationStatus::Unavailable,true);

        $this->actingAs($employee)->get(route('employee.transactions.show',$tx))
            ->assertOk()
            ->assertSee('Reference-assisted payment breakdown')
            ->assertSee('Possible transfer amount')
            ->assertSee('12,000.00 ETB')
            ->assertSee('8.00 ETB')
            ->assertSee('not bank verified');

        $admin=User::create([
            'name'=>'Audit Admin','username'=>'reference_admin',
            'password'=>'test-password-should-be-long','role'=>UserRole::Admin,'is_active'=>true,
        ]);
        $this->actingAs($admin)->get(route('admin.transactions.show',$tx))
            ->assertOk()
            ->assertSee('Cross-receipt reconciliation')
            ->assertSee('Unverified reference hypothesis')
            ->assertSee('12,000.00 ETB');
    }
}
