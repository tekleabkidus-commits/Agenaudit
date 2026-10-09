<?php

namespace Tests\Feature;

use App\Services\Banking\ReceiptIntelligence;
use App\Services\AI\ExtractionGuard;
use App\Enums\EvidenceKind;
use App\Exceptions\ReviewRequiredException;
use App\Exceptions\ClearerScreenshotRequiredException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TelebirrReceiptIntelligenceTest extends TestCase
{
    use RefreshDatabase;
    private function green(array $extra=[]): array
    {
        return array_merge([
            'receipt_template'=>'telebirr_green_success',
            'visual_indicators'=>[
                'green_success_check','dj_reference','transaction_to_label',
                'zemen_gebeya_banner','download_share_bar',
            ],
            'transaction_id'=>'DJ81KTOPXJ',
            'from_bank'=>null,
            'from_bank_label_visible'=>false,
            'to_bank'=>null,
            'transfer_type'=>'unknown',
            'amount'=>30008,
            'amount_role'=>'total_debit',
            'service_fee'=>7,
            'fee_vat'=>1,
            'total_debited'=>30008,
        ], $extra);
    }

    public function test_telebirr_green_pattern_recognizes_source_and_uses_only_explicit_fees(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green());
        $this->assertSame('Telebirr',$result['from_bank']);
        $this->assertEquals(30000,$result['amount']);
        $this->assertSame('receipt_template',$result['_receipt_intelligence']['source_method']);
        $this->assertSame('debit_minus_explicit_fees',$result['_receipt_intelligence']['amount_source']);
        $this->assertFalse($result['_receipt_intelligence']['amount_needs_review']);
        $this->assertNull($result['to_bank']);
    }

    public function test_unknown_fee_cannot_be_guessed_or_automatically_accepted(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'service_fee'=>null,'fee_vat'=>null,
        ]));
        $this->assertEquals(30008,$result['amount']);
        $this->assertTrue($result['_receipt_intelligence']['amount_needs_review']);

        $this->expectException(ReviewRequiredException::class);
        app(ExtractionGuard::class)->assertUsable(array_merge($result,[
            'quality'=>['score'=>.99,'critical_confidence'=>.99,'issues'=>[]],
            'receiver_account'=>'251900000001',
            'receiver_name'=>'Example',
            'transaction_at'=>now()->toIso8601String(),
        ]), EvidenceKind::BankPayment);
    }

    public function test_explicit_pdf_settlement_takes_priority_over_sender_total(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize([
            'receipt_template'=>'telebirr_pdf',
            'visual_indicators'=>['ethio_telecom_header','telebirr_payment_mode'],
            'transaction_id'=>'DJ80K1234',
            'from_bank'=>null,'to_bank'=>'Telebirr',
            'amount'=>8008,'amount_role'=>'total_debit',
            'settled_amount'=>8000,'total_debited'=>8008,
        ]);
        $this->assertEquals(8000,$result['amount']);
        $this->assertSame('Telebirr',$result['from_bank']);
    }

    public function test_transfer_to_abyssinia_identifies_sender_without_changing_destination(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'transfer_type'=>'wallet_to_bank',
            'to_bank'=>'Bank of Abyssinia',
            'receiver_account'=>'11695183',
            'amount'=>50015,'total_debited'=>50015,'service_fee'=>15,'fee_vat'=>0,
        ]));
        $this->assertSame('Telebirr',$result['from_bank']);
        $this->assertSame('Bank of Abyssinia',$result['to_bank']);
        $this->assertEquals(50000,$result['amount']);
    }

    public function test_generic_green_bank_screenshot_is_not_telebirr(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'visual_indicators'=>['green_success_check','dj_reference','transaction_to_label'],
        ]));
        $this->assertNull($result['from_bank']);
        $this->assertSame('unconfirmed',$result['_receipt_intelligence']['family']);
    }

    public function test_zemen_gebeya_ad_does_not_mean_zemen_bank(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'from_bank'=>'Zemen Bank',
            'from_bank_label_visible'=>false,
        ]));
        $this->assertNull($result['from_bank']);
        $this->assertSame('unconfirmed',$result['_receipt_intelligence']['family']);
    }

    public function test_missing_destination_is_never_assumed_to_be_telebirr(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'amount_role'=>'transfer_amount','amount'=>30000,
        ]));
        $this->assertNull($result['to_bank']);
    }

    public function test_explicit_wallet_transfer_with_phone_can_identify_destination(): void
    {
        $result=app(ReceiptIntelligence::class)->normalize($this->green([
            'amount_role'=>'transfer_amount','amount'=>30000,
            'transfer_type'=>'wallet_to_wallet','receiver_account'=>'251911112233',
        ]));
        $this->assertSame('Telebirr',$result['to_bank']);
    }
    public function test_cross_bank_reference_is_checked_against_sender_issuer_not_destination(): void
    {
        $sender=\App\Models\Bank::query()->where('code','TELEBIRR')->firstOrFail();
        $receiver=\App\Models\Bank::query()->where('code','BOA')->firstOrFail();

        $record=new \App\Models\PaymentRecord([
            'transaction_id_raw'=>'DJ91L2R5RT',
            'receiver_account'=>'11695183',
        ]);
        $record->setRelation('fromBank',$sender);
        $record->setRelation('toBank',$receiver);

        $payload=app(\App\Services\Integrations\CheckEtClient::class)->buildPayload($record);
        $this->assertSame('telebirr',$payload['bank']);
        $this->assertSame('DJ91L2R5RT',$payload['transaction_number']);
        $this->assertArrayNotHasKey('account_number',$payload);
    }

}
