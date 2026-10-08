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

    public function test_gemini_rejects_financial_evidence_without_explicit_data_policy_approval(): void
    {
        config()->set('services.ai.driver','gemini');
        config()->set('services.ai.gemini_api_key','synthetic-placeholder');
        config()->set('services.ai.gemini_allow_sensitive_evidence',false);

        Http::fake();
        $evidence=new \App\Models\EvidenceFile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini financial evidence uploads are disabled');

        app(\App\Services\AI\GeminiVisionExtractor::class)->extract($evidence);
    }

    public function test_cloudflare_vision_accepts_two_agent_proofs_as_a_private_collage(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        config()->set('services.ai.cloudflare_account_id','test-account');
        config()->set('services.ai.cloudflare_api_token','test-cloudflare-token');

        Http::fake([
            'api.cloudflare.com/client/v4/accounts/*' => Http::response([
                'success'=>true,
                'result'=>['response'=>json_encode([
                    'quality'=>['score'=>.99,'critical_confidence'=>.99,'issues'=>[]],
                    'agent_id'=>'A-123',
                    'agent_username'=>'testagent',
                    'amount'=>100,
                    'transaction_at'=>'2026-10-08T10:00:00+03:00',
                ])],
            ],200),
        ]);

        $store=\Illuminate\Support\Facades\Storage::disk('private');
        $img=imagecreatetruecolor(220,120);
        $white=imagecolorallocate($img,255,255,255);
        imagefill($img,0,0,$white);
        ob_start();
        imagepng($img);
        $png=ob_get_clean();
        imagedestroy($img);
        $store->put('proof-1.png',$png);
        $store->put('proof-2.png',$png);

        $first=new \App\Models\EvidenceFile([
            'disk'=>'private','path'=>'proof-1.png','mime_type'=>'image/png',
            'kind'=>\App\Enums\EvidenceKind::AgentSystem,
        ]);
        $second=new \App\Models\EvidenceFile([
            'disk'=>'private','path'=>'proof-2.png','mime_type'=>'image/png',
            'kind'=>\App\Enums\EvidenceKind::AgentSystem,
        ]);

        $payload=app(\App\Services\AI\CloudflareVisionExtractor::class)->extractMany([$first,$second]);
        $this->assertEquals(100,$payload['amount']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(),'/ai/run/')
                && str_starts_with((string)$request['image'],'data:image/png;base64,')
                && str_contains((string)$request['messages'][1]['content'],'ONE agent transaction');
        });
    }

}
