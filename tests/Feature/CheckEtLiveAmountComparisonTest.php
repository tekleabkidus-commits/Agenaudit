<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckEtLiveAmountComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.check_et.api_key','synthetic-test-key');
        config()->set('services.check_et.base_url','https://api.check.et');
    }

    private function response(float $amount, string $method='official'): void
    {
        Http::fake([
            'api.check.et/api/v1/verify' => Http::response([
                'success'=>true,
                'exists'=>true,
                'duplicate'=>false,
                'data'=>[
                    'verification_method'=>$method,
                    'receipt'=>[
                        'amount'=>$amount,
                        'currency'=>'ETB',
                        'status'=>'completed',
                        'receiver_name'=>'Private name never printed',
                    ],
                ],
            ],200),
        ]);
    }

    public function test_reports_when_check_et_equals_settled_amount_not_sender_debit(): void
    {
        $this->response(30000);
        $this->artisan('agent-audit:compare-check-et',[
            '--bank'=>'telebirr',
            '--reference'=>'DJEXAMPLE001',
            '--settled'=>'30000',
            '--paid'=>'30008',
        ])->expectsOutput('RESULT: PROVIDER RETURNS SETTLED/TRANSFER AMOUNT for THIS receipt, not the fee-inclusive payer total.')
            ->assertExitCode(0);
        Http::assertSent(fn($request)=>
            $request->hasHeader('Authorization')
            && $request['bank']==='telebirr'
            && $request['transaction_number']==='DJEXAMPLE001'
            && !isset($request['amount']));
        Http::assertSentCount(1);
    }

    public function test_reports_when_check_et_includes_fees(): void
    {
        $this->response(8008);
        $this->artisan('agent-audit:compare-check-et',[
            '--bank'=>'telebirr',
            '--reference'=>'DJEXAMPLE002',
            '--settled'=>'8000',
            '--paid'=>'8008',
        ])->expectsOutput('RESULT: PROVIDER RETURNS PAYER TOTAL for THIS receipt (including fees shown on the invoice).')
            ->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_nonofficial_or_incomplete_response_is_inconclusive(): void
    {
        $this->response(30000,'ocr');
        $this->artisan('agent-audit:compare-check-et',[
            '--bank'=>'telebirr',
            '--reference'=>'DJEXAMPLE003',
            '--settled'=>'30000',
            '--paid'=>'30008',
        ])->expectsOutput('RESULT: INCONCLUSIVE — a confirmed, official, completed ETB receipt was not established.')
            ->assertExitCode(1);
    }

    public function test_missing_key_prevents_request(): void
    {
        config()->set('services.check_et.api_key','');
        Http::fake();
        $this->artisan('agent-audit:compare-check-et',[
            '--bank'=>'telebirr',
            '--reference'=>'DJEXAMPLE004',
            '--settled'=>'8000',
            '--paid'=>'8008',
        ])->assertExitCode(1);
        Http::assertNothingSent();
    }
}
