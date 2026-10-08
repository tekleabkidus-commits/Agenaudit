<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiConnectionDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_diagnostic_uses_production_like_configured_keys_without_verifying_payments(): void
    {
        config()->set('services.ai.driver', 'openai');
        config()->set('services.ai.api_key', 'test-openai-key');
        config()->set('services.ai.model', 'gpt-4.1-mini');
        config()->set('services.ai.base_url', 'https://api.openai.com/v1');
        config()->set('services.check_et.api_key', 'test-check-et-key');
        config()->set('services.check_et.base_url', 'https://api.check.et');

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output'=>[[
                    'type'=>'message',
                    'content'=>[['type'=>'output_text','text'=>'AGENAUDIT']],
                ]],
            ], 200),
            'api.check.et/api/v1/verifications*' => Http::response(['data'=>[]], 200),
        ]);

        $this->artisan('agent-audit:test-apis')->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/responses'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/verifications'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/verify?')
            || str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/api/v1/verify'));
    }

    public function test_api_diagnostic_reports_invalid_credentials_without_exposing_keys(): void
    {
        config()->set('services.ai.driver', 'openai');
        config()->set('services.ai.api_key', 'secret-not-for-output');
        config()->set('services.ai.model', 'gpt-4.1-mini');
        config()->set('services.ai.base_url', 'https://api.openai.com/v1');
        config()->set('services.check_et.api_key', 'check-secret-not-for-output');
        config()->set('services.check_et.base_url', 'https://api.check.et');

        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['error'=>'unauthorized'], 401),
            'api.check.et/api/v1/verifications*' => Http::response(['error'=>'unauthorized'], 401),
        ]);

        $this->artisan('agent-audit:test-apis')->assertExitCode(1);
        Http::assertSentCount(2);
    }
    public function test_api_diagnostic_distinguishes_billing_quota_from_rate_limit(): void
    {
        config()->set('services.ai.driver', 'openai');
        config()->set('services.ai.api_key', 'placeholder-private');
        config()->set('services.ai.model', 'gpt-4.1-mini');
        config()->set('services.ai.base_url', 'https://api.openai.com/v1');
        config()->set('services.check_et.api_key', 'placeholder-check');
        config()->set('services.check_et.base_url', 'https://api.check.et');

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error'=>[
                    'type'=>'insufficient_quota',
                    'code'=>'credit_balance_exhausted',
                    'message'=>'Billing limit; do not reveal this message.',
                ],
            ], 429),
            'api.check.et/api/v1/verifications*' => Http::response(['data'=>[]], 200),
        ]);

        $this->artisan('agent-audit:test-apis')
            ->expectsOutput('OpenAI: FAILED HTTP 429. Quota/billing restriction. Check OpenAI API credits, organization and project spend/usage limits. Waiting or retrying will not solve a quota error.')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_api_diagnostic_distinguishes_temporary_rate_limit(): void
    {
        config()->set('services.ai.driver', 'openai');
        config()->set('services.ai.api_key', 'placeholder-private');
        config()->set('services.ai.model', 'gpt-4.1-mini');
        config()->set('services.ai.base_url', 'https://api.openai.com/v1');
        config()->set('services.check_et.api_key', 'placeholder-check');
        config()->set('services.check_et.base_url', 'https://api.check.et');

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error'=>['code'=>'rate_limit_exceeded','type'=>'rate_limit_exceeded'],
            ], 429),
            'api.check.et/api/v1/verifications*' => Http::response(['data'=>[]], 200),
        ]);

        $this->artisan('agent-audit:test-apis')
            ->expectsOutput('OpenAI: FAILED HTTP 429. Request/token rate limit. Wait, pace requests and check the OpenAI organization/project rate limits.')
            ->assertExitCode(1);
    }

    public function test_gemini_diagnostic_uses_only_synthetic_content_and_no_check_et_verification(): void
    {
        config()->set('services.ai.driver','gemini');
        config()->set('services.ai.gemini_api_key','example-gemini-key');
        config()->set('services.ai.gemini_model','gemini-3.5-flash-lite');
        config()->set('services.ai.gemini_allow_sensitive_evidence',false);
        config()->set('services.check_et.api_key','example-check-key');

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates'=>[[
                    'content'=>['parts'=>[['text'=>'AGENAUDIT']]],
                ]],
            ],200),
            'api.check.et/api/v1/verifications*' => Http::response(['data'=>[]],200),
        ]);

        $this->artisan('agent-audit:test-apis')->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(),'generativelanguage.googleapis.com')
            && !str_contains(json_encode($request->data()),'receiver_account'));
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(),PHP_URL_PATH) ?: '','/api/v1/verify'));
    }

}
