<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Brand;
use App\Models\PaymentInput;
use App\Models\PaymentRecord;
use App\Models\ReceivingAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMessageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $bank=Bank::where('code','TELEBIRR')->firstOrFail();
        $brand=Brand::create(['name'=>'Receipt Test','slug'=>'receipt-test','is_active'=>true]);
        $employee=User::create([
            'name'=>'Test Employee','username'=>'receipt_tester',
            'password'=>'test-password-123','role'=>UserRole::Employee,'is_active'=>true,
        ]);
        $employee->brands()->attach($brand);
        $agent=Agent::create([
            'brand_id'=>$brand->id,'agent_id'=>'SMSG0001','agent_id_normalized'=>'SMSG0001',
            'username'=>'agentexample','username_normalized'=>'AGENTEXAMPLE','is_active'=>true,
        ]);
        $account=ReceivingAccount::create([
            'bank_id'=>$bank->id,'account_number'=>'251900005900',
            'normalized_account_number'=>'251900005900',
            'account_name'=>'Example Receiver',
            'normalized_account_name'=>Normalizer::name('Example Receiver'),
            'name_aliases'=>[],'is_active'=>true,
        ]);
        $account->brands()->attach($brand);
        $transaction=Transaction::create([
            'reference'=>(string)Str::uuid(),'employee_id'=>$employee->id,
            'agent_id'=>$agent->id,'brand_id'=>$brand->id,
            'type'=>TransactionType::PaidTopup,'amount'=>40000,
            'agent_system_at'=>'2026-10-20T06:56:48+03:00','status'=>TransactionStatus::Processing,
        ]);
        app(SettingsService::class)->set('check_et.enabled',true);
        config()->set('services.check_et.api_key','synthetic-key');
        config()->set('services.check_et.enabled',true);
        config()->set('services.check_et.base_url','https://api.check.et');
        return [$transaction,$employee];
    }

    private function sms(): string
    {
        return 'You have transferred ETB 40,000.00 to Example Receiver (2519****5900) on 20/10/2026 06:56:48. '.
            'Your transaction number is DJTESTSMS01. Service fee is ETB 6.96. '.
            'https://transactioninfo.ethiotelecom.et/receipt/DJTESTSMS01';
    }

    private function official(bool $hasAccount=true): array
    {
        return ['success'=>true,'exists'=>true,'duplicate'=>true,'data'=>[
            'bank'=>'telebirr','verification_method'=>'official',
            'receipt'=>array_merge([
                'status'=>'completed','amount'=>40000,'currency'=>'ETB',
                'receiver_name'=>'Example Receiver',
                'transaction_date'=>'2026-10-20T06:56:48+03:00',
            ],$hasAccount?['receiver_account'=>'251900005900']:[]),
        ]];
    }

    public function test_sms_official_amount_is_used_without_adding_service_fees(): void
    {
        [$tx,$user]=$this->fixture();
        Http::fake(['api.check.et/api/v1/verify'=>Http::response($this->official(),200)]);
        $this->actingAs($user)
            ->post(route('employee.transactions.payment-messages',$tx),['messages'=>[$this->sms(),'','']])
            ->assertRedirect();

        $this->assertDatabaseCount('payment_inputs',1);
        $this->assertDatabaseCount('payment_records',1);
        $this->assertSame('verified',PaymentInput::firstOrFail()->status);
        $record=PaymentRecord::firstOrFail();
        $this->assertEquals(40000,$record->amount);
        $this->assertNull($record->evidence_file_id);
        $this->assertEquals(40000,$tx->fresh()->valid_payment_total);
        Http::assertSent(fn($request)=>isset($request['receipt_url'])
            && !isset($request['receipt_text'])
            && !isset($request['amount']));
    }

    public function test_official_amount_without_proved_destination_is_not_approved(): void
    {
        [$tx,$user]=$this->fixture();
        Http::fake(['api.check.et/api/v1/verify'=>Http::response($this->official(false),200)]);
        $this->actingAs($user)->post(route('employee.transactions.payment-messages',$tx),
            ['messages'=>[$this->sms()]])->assertRedirect();

        $this->assertSame('review',PaymentInput::firstOrFail()->status);
        $this->assertEquals(0,$tx->fresh()->valid_payment_total);
        $this->assertSame(TransactionStatus::PendingAdminReview,$tx->fresh()->status);
    }

    public function test_outage_keeps_message_for_recheck_and_detects_reuse(): void
    {
        [$tx,$user]=$this->fixture();
        Http::fake(['api.check.et/api/v1/verify'=>Http::sequence()
            ->push([],503)->push($this->official(),200)]);
        $this->actingAs($user)->post(route('employee.transactions.payment-messages',$tx),
            ['messages'=>[$this->sms()]])->assertRedirect();
        $input=PaymentInput::firstOrFail();
        $this->assertSame('unavailable',$input->status);
        $this->assertDatabaseCount('payment_records',0);

        $this->actingAs($user)->post(
            route('employee.transactions.payment-messages.recheck',[$tx,$input])
        )->assertRedirect();
        $this->assertSame('verified',$input->fresh()->status);
        $this->assertDatabaseCount('payment_records',1);

        $this->actingAs($user)->post(route('employee.transactions.payment-messages',$tx),
            ['messages'=>[$this->sms()]])->assertRedirect();
        $this->assertSame('duplicate',PaymentInput::latest('id')->firstOrFail()->status);
        $this->assertDatabaseCount('payment_records',1);
    }
}
