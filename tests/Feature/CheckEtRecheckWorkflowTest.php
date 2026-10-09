<?php

namespace Tests\Feature;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Enums\ExternalVerificationStatus;
use App\Enums\PaymentValidationStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Jobs\RecheckExternalPaymentsJob;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\ReceivingAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\PaymentVerificationService;
use App\Services\Banking\ReceiptIntelligence;
use App\Services\SettingsService;
use App\Services\Transactions\TransactionWorkflowService;
use App\Support\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckEtRecheckWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $bank = Bank::query()->where('code','CBE')->firstOrFail();
        $bank->update([
            'check_et_enabled'=>true,
            'check_et_amount_meaning'=>'transfer_amount',
        ]);
        config()->set('services.check_et.enabled',true);
        config()->set('services.check_et.api_key','synthetic-secret');
        config()->set('services.check_et.base_url','https://api.check.et');
        app(SettingsService::class)->set('check_et.enabled',true);

        $brand=Brand::create(['name'=>'Recheck Kemer','slug'=>'recheck-kemer','is_active'=>true]);
        $employee=User::create([
            'name'=>'Receipt Operator','username'=>'receipt_operator',
            'password'=>'some-test-password','role'=>UserRole::Employee,'is_active'=>true,
        ]);
        $employee->brands()->attach($brand);

        $agent=Agent::create([
            'brand_id'=>$brand->id,
            'agent_id'=>'RCT001','agent_id_normalized'=>'RCT001',
            'username'=>'rc_agent','username_normalized'=>'RC_AGENT','is_active'=>true,
        ]);
        $receiving=ReceivingAccount::create([
            'bank_id'=>$bank->id,
            'account_number'=>'100012346273',
            'normalized_account_number'=>'100012346273',
            'account_name'=>'Safety Trading PLC',
            'normalized_account_name'=>Normalizer::name('Safety Trading PLC'),
            'name_aliases'=>[],'is_active'=>true,
        ]);
        $receiving->brands()->attach($brand);
        $transaction=Transaction::create([
            'reference'=>(string)Str::uuid(),
            'employee_id'=>$employee->id,
            'agent_id'=>$agent->id,
            'brand_id'=>$brand->id,
            'amount'=>30000,
            'agent_system_at'=>now(),
            'type'=>TransactionType::PaidTopup,
            'status'=>TransactionStatus::Processing,
        ]);
        return [$transaction,$employee];
    }

    private function response(float $amount): array
    {
        return [
            'success'=>true, 'exists'=>true,
            'data'=>[
                'verification_method'=>'official',
                'receipt'=>[
                    'amount'=>$amount,
                    'currency'=>'ETB',
                    'status'=>'completed',
                    'receiver_name'=>'Safety Trading PLC',
                ],
            ],
        ];
    }

    private function receipt(Transaction $tx, int $number, float $debit, bool $knownPrincipal): PaymentRecord
    {
        $evidence=EvidenceFile::create([
            'transaction_id'=>$tx->id,
            'kind'=>EvidenceKind::BankPayment,
            'sequence'=>$number,
            'disk'=>'private',
            'path'=>"tests/recheck-{$number}.png",
            'original_name'=>"receipt-{$number}.png",
            'mime_type'=>'image/png',
            'size_bytes'=>200,
            'sha256'=>hash('sha256',"receipt-{$number}"),
            'status'=>EvidenceStatus::Extracted,
        ]);
        $source=[
            'quality'=>['score'=>.99,'critical_confidence'=>.99,'issues'=>[]],
            'from_bank'=>'CBE','from_bank_label_visible'=>true,
            'to_bank'=>'CBE',
            'sender_account'=>'99119911',
            'sender_name'=>'Payer Test',
            'receiver_account'=>'100012346273',
            'receiver_name'=>'Safety Trading PLC',
            'transaction_id'=>'FTRECHECK'.str_pad((string)$number,4,'0',STR_PAD_LEFT),
            'transaction_at'=>now()->toIso8601String(),
            'status'=>'Completed',
            'amount'=>$debit,
            'amount_role'=>$knownPrincipal?'transfer_amount':'total_debit',
            'settled_amount'=>$knownPrincipal?$debit:null,
            'total_debited'=>$debit,
            'service_fee'=>null,'fee_vat'=>null,
            'fee_components_complete'=>false,
        ];
        $payload=app(ReceiptIntelligence::class)->normalize($source);
        $evidence->update(['extracted'=>$payload,'raw_ai_response'=>$source]);
        return app(PaymentVerificationService::class)->apply($tx,$evidence,$payload);
    }

    public function test_three_receipts_one_topup_and_outage_recovery_without_double_counting(): void
    {
        [$transaction]=$this->fixture();

        $api = Http::sequence()
            ->push($this->response(10000))
            ->push($this->response(8000))
            ->push(['message'=>'temporarily unavailable'],503);
        Http::fake(['api.check.et/api/v1/verify'=>$api]);

        $one=$this->receipt($transaction,1,10000,true);
        $two=$this->receipt($transaction,2,8000,true);
        $third=$this->receipt($transaction,3,12008,false);
        $this->assertSame(PaymentValidationStatus::Valid,$one->internal_status);
        $this->assertSame(PaymentValidationStatus::Valid,$two->internal_status);
        $this->assertSame(PaymentValidationStatus::Review,$third->internal_status);
        $this->assertSame('transfer_amount_unconfirmed',$third->rejection_code);

        $before=app(TransactionWorkflowService::class)->recalculate($transaction);
        $this->assertSame(TransactionStatus::PendingAdminReview,$before->status);
        $this->assertEquals(18000,$before->valid_payment_total);

        Http::fake(['api.check.et/api/v1/verify'=>Http::response($this->response(12000),200)]);

        app(RecheckExternalPaymentsJob::class, [
            'transactionId'=>$transaction->id,
            'paymentId'=>$third->id,
            'requestedById'=>null,
        ])->handle(
            app(PaymentVerificationService::class),
            app(TransactionWorkflowService::class),
            app(\App\Services\Transactions\TransactionEventLogger::class)
        );

        $after=$transaction->fresh();
        $this->assertSame(TransactionStatus::ReadyForReview,$after->status);
        $this->assertEquals(30000,$after->valid_payment_total);
        $this->assertEquals(0,$after->difference);
        $this->assertEquals(12000,$third->fresh()->amount);
        $this->assertSame(3,$transaction->payments()->count());
        $this->assertSame(3,$transaction->evidenceFiles()->count());
        $this->assertSame(1,$transaction->events()->where('event_type','check_et_rechecked')->count());
    }

    public function test_employee_can_queue_recheck_own_transaction_but_not_others(): void
    {
        [$tx,$owner]=$this->fixture();
        Http::fake(['api.check.et/api/v1/verify'=>Http::response($this->response(10000),200)]);
        $payment=$this->receipt($tx,1,10000,true);

        Queue::fake();

        $this->actingAs($owner)
            ->post(route('employee.transactions.payments.recheck-check-et',[$tx,$payment]))
            ->assertRedirect();
        Queue::assertPushed(RecheckExternalPaymentsJob::class, fn ($j) =>
            $j->transactionId === $tx->id && $j->paymentId === $payment->id
        );

        $other=User::create([
            'name'=>'Unrelated','username'=>'unrelated',
            'password'=>'some-test-password',
            'role'=>UserRole::Employee,'is_active'=>true,
        ]);
        $this->actingAs($other)
            ->post(route('employee.transactions.recheck-check-et',$tx))
            ->assertForbidden();
        $this->actingAs($other)
            ->post(route('employee.transactions.payments.recheck-check-et',[$tx,$payment]))
            ->assertForbidden();
        Queue::assertPushed(RecheckExternalPaymentsJob::class,1);
    }

    public function test_rejected_duplicate_cannot_be_rechecked(): void
    {
        [$tx,$owner]=$this->fixture();
        $payment=PaymentRecord::create([
            'transaction_id'=>$tx->id,
            'amount'=>10000,
            'internal_status'=>PaymentValidationStatus::Rejected,
            'external_status'=>ExternalVerificationStatus::NotRequired,
            'rejection_code'=>'duplicate_transaction_id',
            'transaction_id_raw'=>'TESTDUPLICATEX',
        ]);
        Queue::fake();
        $this->actingAs($owner)
            ->post(route('employee.transactions.payments.recheck-check-et',[$tx,$payment]))
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_employee_page_displays_recheck_and_reference_total(): void
    {
        [$tx,$owner]=$this->fixture();
        Http::fake(['api.check.et/api/v1/verify'=>Http::response($this->response(10000),200)]);
        $this->receipt($tx,1,10000,true);
        app(TransactionWorkflowService::class)->recalculate($tx);

        $this->actingAs($owner)
            ->get(route('employee.transactions.show',$tx))
            ->assertOk()
            ->assertSee('One agent top-up')
            ->assertSee('30,000.00 ETB')
            ->assertSee('Recheck all')
            ->assertSee('Recheck this receipt');
    }
}
