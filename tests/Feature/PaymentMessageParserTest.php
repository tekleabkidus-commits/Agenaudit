<?php

namespace Tests\Feature;

use App\Services\Banking\PaymentMessageParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentMessageParserTest extends TestCase
{
    public function test_telebirr_link_extracts_issuer_and_reference_without_fetching_url(): void
    {
        $parsed=app(PaymentMessageParser::class)->parse(
            'https://transactioninfo.ethiotelecom.et/receipt/DJK93IHH1D'
        );
        $this->assertSame('receipt_url',$parsed['input_type']);
        $this->assertSame('telebirr',$parsed['bank']);
        $this->assertSame('DJK93IHH1D',$parsed['reference']);
        $this->assertNull($parsed['amount_hint']);
    }

    public function test_telebirr_sms_extracts_principal_separate_from_fee(): void
    {
        $sms="Dear Taju\nYou have transferred ETB 40,000.00 to Berihu yemane (2519****5900) on 20/10/2026 06:56:48. ".
            "Your transaction number is DJK72YXXGJ. The service fee is ETB 6.96 and 15% VAT ".
            "on the service fee is ETB 1.04. Your current E-Money Account balance is ETB 7,298.51. ".
            "To download please click this link: https://transactioninfo.ethiotelecom.et/receipt/DJK72YXXGJ.";
        $parsed=app(PaymentMessageParser::class)->parse($sms);
        $this->assertSame('receipt_text',$parsed['input_type']);
        $this->assertSame('DJK72YXXGJ',$parsed['reference']);
        $this->assertSame('telebirr',$parsed['bank']);
        $this->assertSame('2519****5900',$parsed['receiver_account']);
        $this->assertSame('Berihu yemane',$parsed['receiver_name']);
        $this->assertSame(40000.0,$parsed['amount_hint']);
        $this->assertSame(6.96,$parsed['sender_fee']);
        $this->assertSame(1.04,$parsed['sender_fee_vat']);
        $this->assertStringContainsString('2026-10-20',$parsed['transaction_at']);
        $this->assertSame('https://transactioninfo.ethiotelecom.et/receipt/DJK72YXXGJ',$parsed['url']);
    }

    public function test_conflicting_reference_in_text_and_link_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PaymentMessageParser::class)->parse('Your transaction number is DJK72YXXGJ. '.
            'https://transactioninfo.ethiotelecom.et/receipt/DJK93IHH1D');
    }

    public function test_non_official_or_local_links_are_rejected(): void
    {
        foreach (['http://transactioninfo.ethiotelecom.et/receipt/DJK93IHH1D',
            'https://localhost/receipt/DJK93IHH1D',
            'https://transactioninfo.ethiotelecom.et.evil.test/receipt/DJK93IHH1D',
            'https://transactioninfo.ethiotelecom.et@attacker.test/receipt/DJK93IHH1D',
        ] as $url) {
            try {
                app(PaymentMessageParser::class)->parse($url);
                $this->fail('Unapproved receipt host was accepted.');
            } catch (ValidationException $expected) {
                $this->assertNotEmpty($expected->errors());
            }
        }
    }
}
