<?php

namespace Tests\Feature;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\ExternalVerificationStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\ReceivingAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\ReceiptIntelligence;
use App\Services\Banking\PaymentVerificationService;
use App\Services\SettingsService;
use App\Support\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankFeeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function example(array $overrides=[]): array
    {
        return array_replace([
            'from_bank'=>'CBE',
            'from_bank_label_visible'=>true,
            'to_bank'=>'CBE',
            'receiver_account'=>'100012346273',
            'receiver_name'=>'Safety Trading PLC',
            'sender_account'=>'99900001',
            'sender_name'=>'Sender Test',
            'amount'=>10008,
            'amount_role'=>'total_debit',
            'total_debited'=>10008,
            'settled_amount'=>null,
            'service_fee'=>null,
            'fee_vat'=>null,
            'other_fees'=>null,
            'total_fees'=>null,
            'fee_components_complete'=>false,
            'transaction_id'=>'FTFEE20261009',
            'transaction_at'=>now()->toIso8601String(),
            'status'=>'Completed',
            'quality'=>['score'=>0.99,'critical_confidence'=>0.99,'issues'=>[]],
        ],$overrides);
    }

    private function fixture(string $meaning): array
    {
        $bank=Bank::query()->where('code','CBE')->firstOrFail();
        $bank->update([
            'check_et_enabled'=>true,
            'check_et_amount_meaning'=>$meaning,
        ]);
        config()->set('services.check_et.api_key','synthetic-key');
        config()->set('services.check_et.enabled',true);
        app(SettingsService::class)->set('check_et.enabled',true);

        $brand=Brand::create(['name'=>'Fee Audit Brand','slug'=>'fee-audit-brand','is_active'=>true]);
        $employee=User::create(['name'=>'Fee Audit','username'=>'fee_audit','password'=>'securepassword',
            'role'=>UserRole::Employee,'is_active'=>true]);
        $employee->brands()->attach($brand);
        $agent=Agent::create([
            'brand_id'=>$brand->id,'agent_id'=>'FEE001','agent_id_normalized'=>'FEE001',
            'username'=>'fee_user','username_normalized'=>'FEE_USER','is_active'=>true,
        ]);
        $account=ReceivingAccount::create([
            'bank_id'=>$bank->id,'account_number'=>'100012346273',
            'normalized_account_number'=>'100012346273','account_name'=>'Safety Trading PLC',
            'normalized_account_name'=>Normalizer::name('Safety Trading PLC'),
            'name_aliases'=>[],'is_active'=>true,
        ]);
        $account->brands()->attach($brand);
        $transaction=Transaction::create([
            'reference'=>(string)Str::uuid(),'employee_id'=>$employee->id,
            'brand_id'=>$brand->id,'agent_id'=>$agent->id,
            'amount'=>10000,'type'=>TransactionType::PaidTopup,
            'status'=>TransactionStatus::Processing,'agent_system_at'=>now(),
        ]);
        $evidence=EvidenceFile::create([
            'transaction_id'=>$transaction->id,
            'kind'=>EvidenceKind::BankPayment,'sequence'=>1,'disk'=>'private',
            'path'=>'tests/fee-receipt.png','original_name'=>'fee-receipt.png','mime_type'=>'image/png',
            'size_bytes'=>20,'sha256'=>hash('sha256','fee-test'),'status'=>EvidenceStatus::Extracted,
        ]);
        return [$transaction,$evidence];
    }

    private function officialResponse(float $amount, string $method='official'): void
    {
        Http::fake(['api.check.et/api/v1/verify' => Http::response([
            'success'=>true,'exists'=>true,'data'=>[
                'bank'=>'cbe','verification_method'=>$method,
                'receipt'=>[
                    'amount'=>$amount,'currency'=>'ETB','status'=>'completed',
                    'receiver_name'=>'Safety Trading PLC',
                ],
            ],
        ],200)]);
    }

    public function test_any_bank_accepts_labeled_transfer_amount_even_if_fees_not_visible(): void
    {
        $payload=app(ReceiptIntelligence::class)->normalize($this->example([
            'amount'=>10000,'amount_role'=>'transfer_amount','total_debited'=>null,
        ]));
        $this->assertEquals(10000,$payload['amount']);
        $this->assertFalse($payload['_receipt_intelligence']['amount_needs_review']);
        $this->assertSame('explicit_transfer_amount',$payload['_receipt_intelligence']['amount_source']);
    }

    public function test_any_bank_requires_review_if_payer_debit_includes_unlisted_fees(): void
    {
        $payload=app(ReceiptIntelligence::class)->normalize($this->example());
        $this->assertEquals(10008,$payload['amount']);
        $this->assertTrue($payload['_receipt_intelligence']['amount_needs_review']);
    }

    public function test_partial_service_fee_is_not_assumed_to_cover_all_charges(): void
    {
        $payload=app(ReceiptIntelligence::class)->normalize($this->example([
            'service_fee'=>8,'fee_vat'=>null,'fee_components_complete'=>false,
        ]));
        $this->assertTrue($payload['_receipt_intelligence']['amount_needs_review']);
        $this->assertEquals(10008,$payload['amount']);
    }

    public function test_complete_itemized_fees_can_be_subtracted_without_double_counting(): void
    {
        $payload=app(ReceiptIntelligence::class)->normalize($this->example([
            'service_fee'=>6,'fee_vat'=>1,'other_fees'=>1,
            'total_fees'=>8,'fee_components_complete'=>true,
        ]));
        $this->assertEquals(10000,$payload['amount']);
        $this->assertSame('debit_minus_explicit_fees',$payload['_receipt_intelligence']['amount_source']);
        $this->assertFalse($payload['_receipt_intelligence']['amount_needs_review']);
    }

    public function test_unknown_check_et_amount_semantics_does_not_convert_debit_to_valid_payment(): void
    {
        [$tx,$evidence]=$this->fixture('unknown');
        $this->officialResponse(10000);
        $payload=app(ReceiptIntelligence::class)->normalize($this->example());
        $payment=app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
        $this->assertSame(PaymentValidationStatus::Review,$payment->internal_status);
        $this->assertSame('transfer_amount_unconfirmed',$payment->rejection_code);
        $this->assertEquals(10008,$payment->amount);
    }

    public function test_confirmed_official_transfer_amount_reconciles_unknown_payer_fee(): void
    {
        [$tx,$evidence]=$this->fixture('transfer_amount');
        $this->officialResponse(10000);
        $payload=app(ReceiptIntelligence::class)->normalize($this->example());
        $payment=app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
        $this->assertSame(PaymentValidationStatus::Valid,$payment->internal_status);
        $this->assertSame(ExternalVerificationStatus::Passed,$payment->external_status);
        $this->assertEquals(10000,$payment->amount);
    }

    public function test_confirmed_total_debit_semantics_cannot_resolve_missing_fee(): void
    {
        [$tx,$evidence]=$this->fixture('total_debit');
        $this->officialResponse(10008);
        $payload=app(ReceiptIntelligence::class)->normalize($this->example());
        $payment=app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
        $this->assertSame(PaymentValidationStatus::Review,$payment->internal_status);
        $this->assertEquals(10008,$payment->amount);
    }

    public function test_nonofficial_response_cannot_substitute_for_transfer_amount(): void
    {
        [$tx,$evidence]=$this->fixture('transfer_amount');
        $this->officialResponse(10000,'ocr');
        $payload=app(ReceiptIntelligence::class)->normalize($this->example());
        $payment=app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
        $this->assertSame(PaymentValidationStatus::Review,$payment->internal_status);
    }

    public function test_mismatched_provider_amount_goes_to_review_without_accusing_fraud_when_fees_unknown(): void
    {
        [$tx,$evidence]=$this->fixture('unknown');
        $this->officialResponse(10008);
        $payload=app(ReceiptIntelligence::class)->normalize($this->example([
            'amount'=>10000,'amount_role'=>'transfer_amount',
            'total_debited'=>null,
        ]));
        $payment=app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
        $this->assertSame(PaymentValidationStatus::Review,$payment->internal_status);
        $this->assertSame('verification_amount_semantics_unknown',$payment->rejection_code);
    }
}
