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
            'https://transactioninfo.ethiotelecom.et/receipt/DJTESTLINK01'
        );
        $this->assertSame('receipt_url',$parsed['input_type']);
        $this->assertSame('telebirr',$parsed['bank']);
        $this->assertSame('DJTESTLINK01',$parsed['reference']);
        $this->assertNull($parsed['amount_hint']);
    }

    public function test_telebirr_sms_extracts_principal_separate_from_fee(): void
    {
        $sms="Dear Test\nYou have transferred ETB 40,000.00 to Example Recipient (2519****1234) on 20/10/2026 06:56:48. ".
            "Your transaction number is DJTESTTEXT01. The service fee is ETB 6.96 and 15% VAT ".
            "on the service fee is ETB 1.04. Your current E-Money Account balance is ETB 7,298.51. ".
            "To download please click this link: https://transactioninfo.ethiotelecom.et/receipt/DJTESTTEXT01.";
        $parsed=app(PaymentMessageParser::class)->parse($sms);
        $this->assertSame('receipt_text',$parsed['input_type']);
        $this->assertSame('DJTESTTEXT01',$parsed['reference']);
        $this->assertSame('telebirr',$parsed['bank']);
        $this->assertSame('2519****1234',$parsed['receiver_account']);
        $this->assertSame('Example Recipient',$parsed['receiver_name']);
        $this->assertSame(40000.0,$parsed['amount_hint']);
        $this->assertSame(6.96,$parsed['sender_fee']);
        $this->assertSame(1.04,$parsed['sender_fee_vat']);
        $this->assertStringContainsString('2026-10-20',$parsed['transaction_at']);
        $this->assertSame('https://transactioninfo.ethiotelecom.et/receipt/DJTESTTEXT01',$parsed['url']);
    }

    public function test_conflicting_reference_in_text_and_link_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PaymentMessageParser::class)->parse('Your transaction number is DJTESTTEXT01. '.
            'https://transactioninfo.ethiotelecom.et/receipt/DJTESTLINK01');
    }

    public function test_non_official_or_local_links_are_rejected(): void
    {
        foreach (['http://transactioninfo.ethiotelecom.et/receipt/DJTESTLINK01',
            'https://localhost/receipt/DJTESTLINK01',
            'https://transactioninfo.ethiotelecom.et.evil.test/receipt/DJTESTLINK01',
            'https://transactioninfo.ethiotelecom.et@attacker.test/receipt/DJTESTLINK01',
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
