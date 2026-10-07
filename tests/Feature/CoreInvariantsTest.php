<?php
namespace Tests\Feature;

use App\Enums\EvidenceKind; use App\Enums\EvidenceStatus; use App\Enums\ExternalVerificationStatus; use App\Enums\PaymentValidationStatus; use App\Enums\TransactionStatus; use App\Enums\TransactionType; use App\Enums\UserRole;
use App\Models\Agent; use App\Models\Bank; use App\Models\Brand; use App\Models\EvidenceFile; use App\Models\PaymentRecord; use App\Models\ReceivingAccount; use App\Models\Transaction; use App\Models\User;
use App\Services\Banking\ReceivingAccountMatcher; use App\Services\Integrations\CheckEtClient; use App\Services\SettingsService; use App\Support\Normalizer; use Illuminate\Database\QueryException; use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;

class CoreInvariantsTest extends TestCase
{
 use RefreshDatabase;
 private function user(): User { return User::create(['name'=>'E','username'=>'e'.Str::random(8),'password'=>'password12345','role'=>UserRole::Employee,'is_active'=>true]); }
 private function brand(string $name): Brand { return Brand::create(['name'=>$name,'slug'=>Str::slug($name).'-'.Str::lower(Str::random(4)),'is_active'=>true]); }

 public function test_agent_id_and_username_are_globally_unique_across_brands(): void
 {
  $b1=$this->brand('Brand A');$b2=$this->brand('Brand B');
  Agent::create(['brand_id'=>$b1->id,'agent_id'=>'AG1001','agent_id_normalized'=>'AG1001','username'=>'abebe01','username_normalized'=>'ABEBE01']);
  $this->expectException(QueryException::class);
  Agent::create(['brand_id'=>$b2->id,'agent_id'=>'AG1001','agent_id_normalized'=>'AG1001','username'=>'different','username_normalized'=>'DIFFERENT']);
 }

 public function test_one_receiving_account_can_be_approved_for_multiple_brands_and_masked_match_uses_name(): void
 {
  $b1=$this->brand('Brand A');$b2=$this->brand('Brand B');$bank=Bank::create(['code'=>'CBE','name'=>'Commercial Bank of Ethiopia','aliases'=>['CBE'],'is_active'=>true]);
  $a=ReceivingAccount::create(['bank_id'=>$bank->id,'account_number'=>'100012346273','normalized_account_number'=>'100012346273','account_name'=>'ABC Trading PLC','normalized_account_name'=>Normalizer::name('ABC Trading PLC'),'name_aliases'=>[],'is_active'=>true]);$a->brands()->sync([$b1->id,$b2->id]);
  $match=app(ReceivingAccountMatcher::class)->match($b1,$bank,'1000***6273','ABC TRADING P.L.C.');
  $this->assertSame('matched',$match->status);$this->assertSame($a->id,$match->account?->id);
 }

 public function test_bank_transaction_id_is_globally_unique_even_between_brands(): void
 {
  $b1=$this->brand('Brand A');$b2=$this->brand('Brand B');$u=$this->user();
  $t1=Transaction::create(['reference'=>(string)Str::uuid(),'brand_id'=>$b1->id,'employee_id'=>$u->id,'type'=>TransactionType::PaidTopup,'status'=>TransactionStatus::Processing,'external_verification_status'=>ExternalVerificationStatus::NotRequired]);
  $t2=Transaction::create(['reference'=>(string)Str::uuid(),'brand_id'=>$b2->id,'employee_id'=>$u->id,'type'=>TransactionType::PaidTopup,'status'=>TransactionStatus::Processing,'external_verification_status'=>ExternalVerificationStatus::NotRequired]);
  $e1=EvidenceFile::create(['transaction_id'=>$t1->id,'kind'=>EvidenceKind::BankPayment,'sequence'=>1,'disk'=>'private','path'=>'x','original_name'=>'x.png','mime_type'=>'image/png','size_bytes'=>1,'sha256'=>str_repeat('a',64),'status'=>EvidenceStatus::Extracted]);
  $e2=EvidenceFile::create(['transaction_id'=>$t2->id,'kind'=>EvidenceKind::BankPayment,'sequence'=>1,'disk'=>'private','path'=>'y','original_name'=>'y.png','mime_type'=>'image/png','size_bytes'=>1,'sha256'=>str_repeat('b',64),'status'=>EvidenceStatus::Extracted]);
  PaymentRecord::create(['transaction_id'=>$t1->id,'evidence_file_id'=>$e1->id,'amount'=>100,'transaction_id_raw'=>'FT 123','normalized_transaction_id'=>'FT123','internal_status'=>PaymentValidationStatus::Valid,'external_status'=>ExternalVerificationStatus::Disabled]);
  $this->expectException(QueryException::class);
  PaymentRecord::create(['transaction_id'=>$t2->id,'evidence_file_id'=>$e2->id,'amount'=>100,'transaction_id_raw'=>'ft-123','normalized_transaction_id'=>'FT123','internal_status'=>PaymentValidationStatus::Valid,'external_status'=>ExternalVerificationStatus::Disabled]);
 }

 public function test_check_et_payload_contains_no_domain_brand_agent_employee_or_purpose(): void
 {
  $bank=new Bank(['code'=>'CBE','name'=>'CBE','check_et_code'=>'cbe','check_et_requires_account'=>true]);$account=new ReceivingAccount(['account_number'=>'1000123456789']);$p=new PaymentRecord(['transaction_id_raw'=>'FT123']);$p->setRelation('toBank',$bank);$p->setRelation('receivingAccount',$account);
  $payload=(new CheckEtClient(new SettingsService()))->buildPayload($p);
  $this->assertSame(['bank'=>'cbe','transaction_number'=>'FT123','account_number'=>'1000123456789'],$payload);
  foreach(['domain','origin','referer','brand','agent_id','employee_id','purpose','amount'] as $forbidden)$this->assertArrayNotHasKey($forbidden,$payload);
 }
}
