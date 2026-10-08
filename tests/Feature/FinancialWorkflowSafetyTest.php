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
use App\Models\Bank;
use App\Models\Brand;
use App\Models\EvidenceFile;
use App\Models\PaymentRecord;
use App\Models\ReceivingAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\PaymentVerificationService;
use App\Services\Transactions\TransactionWorkflowService;
use App\Support\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialWorkflowSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $brand = Brand::create(['name'=>'Safety Brand','slug'=>'safety-brand','is_active'=>true]);
        $employee = User::create(['name'=>'Safety Tester','username'=>'safety_tester','password'=>'long_password_123','role'=>UserRole::Employee,'is_active'=>true]);
        $employee->brands()->attach($brand);
        $agent = Agent::create([
            'brand_id'=>$brand->id,'agent_id'=>'SAFE001','agent_id_normalized'=>'SAFE001',
            'username'=>'safe_user','username_normalized'=>'SAFE_USER','is_active'=>true,
        ]);
        $bank = Bank::where('code','CBE')->firstOrFail();
        $account = ReceivingAccount::create([
            'bank_id'=>$bank->id,'account_number'=>'100012346273',
            'normalized_account_number'=>'100012346273','account_name'=>'Safety Trading PLC',
            'normalized_account_name'=>Normalizer::name('Safety Trading PLC'),
            'name_aliases'=>[],'is_active'=>true,
        ]);
        $account->brands()->attach($brand);
        $tx = Transaction::create([
            'reference'=>(string)Str::uuid(),'employee_id'=>$employee->id,
            'agent_id'=>$agent->id,'brand_id'=>$brand->id,
            'amount'=>50000,'agent_system_at'=>now(),
            'type'=>TransactionType::PaidTopup,'status'=>TransactionStatus::Processing,
            'external_verification_status'=>ExternalVerificationStatus::Disabled,
        ]);
        return [$brand,$employee,$agent,$bank,$account,$tx];
    }

    private function evidence(Transaction $transaction, EvidenceStatus $status, int $sequence): EvidenceFile
    {
        return EvidenceFile::create([
            'transaction_id'=>$transaction->id,
            'kind'=>EvidenceKind::BankPayment,
            'sequence'=>$sequence,
            'disk'=>'private',
            'path'=>'tests/'.Str::uuid().'.png',
            'original_name'=>'test.png',
            'mime_type'=>'image/png',
            'size_bytes'=>1024,
            'sha256'=>hash('sha256',Str::random(20)),
            'status'=>$status,
        ]);
    }

    public function test_failed_receipt_does_not_count_as_valid_payment(): void
    {
        [, , , , , $transaction] = $this->fixture();
        $evidence = $this->evidence($transaction, EvidenceStatus::Extracted,1);
        $payment = app(PaymentVerificationService::class)->apply($transaction,$evidence,[
            'from_bank'=>'CBE','to_bank'=>'CBE','sender_account'=>'99900001',
            'sender_name'=>'Sender Test','receiver_account'=>'1000***6273',
            'receiver_name'=>'Safety Trading PLC','amount'=>50000,
            'transaction_id'=>'FT999111001','transaction_at'=>now()->toIso8601String(),
            'status'=>'Failed',
        ]);
        $this->assertSame(PaymentValidationStatus::Rejected,$payment->internal_status);
        $this->assertSame('payment_not_successful',$payment->rejection_code);
        $recalculated = app(TransactionWorkflowService::class)->recalculate($transaction);
        $this->assertSame(0.0,(float)$recalculated->valid_payment_total);
    }

    public function test_a_queued_bank_screenshot_prevents_early_finalization(): void
    {
        [, , , $bank,$account,$transaction] = $this->fixture();
        $one = $this->evidence($transaction,EvidenceStatus::Extracted,1);
        $two = $this->evidence($transaction,EvidenceStatus::Queued,2);

        PaymentRecord::create([
            'transaction_id'=>$transaction->id,'evidence_file_id'=>$one->id,
            'from_bank_id'=>$bank->id,'to_bank_id'=>$bank->id,
            'receiving_account_id'=>$account->id,'amount'=>50000,
            'normalized_transaction_id'=>'FINISHEDFIRST',
            'transaction_id_raw'=>'FINISHEDFIRST',
            'internal_status'=>PaymentValidationStatus::Valid,
            'external_status'=>ExternalVerificationStatus::Disabled,
        ]);

        $workflow = app(TransactionWorkflowService::class);
        $status = $workflow->recalculate($transaction)->status;
        $this->assertSame(TransactionStatus::Processing,$status);

        $two->update(['status'=>EvidenceStatus::Extracted]);
        $status = $workflow->recalculate($transaction)->status;
        $this->assertSame(TransactionStatus::ReadyForReview,$status);
    }

    public function test_unassigned_brand_cannot_be_used_by_employee(): void
    {
        [$brand,$employee] = $this->fixture();
        $other = Brand::create(['name'=>'Other Company','slug'=>'other-company','is_active'=>true]);
        $this->assertTrue($employee->canAccessBrand($brand->id));
        $this->assertFalse($employee->canAccessBrand($other->id));
    }
}
