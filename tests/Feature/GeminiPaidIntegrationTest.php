<?php

namespace Tests\Feature;

use App\Enums\EvidenceKind;
use App\Models\EvidenceFile;
use App\Services\AI\GeminiVisionExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GeminiPaidIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function configureGemini(): void
    {
        config()->set('services.ai.driver', 'gemini');
        config()->set('services.ai.gemini_api_key', 'fake-gemini-test-key');
        config()->set('services.ai.gemini_model', 'gemini-3.5-flash-lite');
        config()->set('services.ai.gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta');
        config()->set('services.ai.gemini_allow_sensitive_evidence', true);
        Storage::fake('private');
    }

    private function proof(string $path): EvidenceFile
    {
        Storage::disk('private')->put($path, 'synthetic-png-payload');

        return new EvidenceFile([
            'kind' => EvidenceKind::AgentSystem,
            'disk' => 'private',
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }

    public function test_paid_gemini_extracts_multiple_proofs_with_structured_schema(): void
    {
        $this->configureGemini();
        $payload = [
            'quality' => ['score' => 0.98, 'critical_confidence' => 0.97, 'issues' => []],
            'agent_id' => 'A-123',
            'agent_username' => 'agent-test',
            'brand_hint' => null,
            'amount' => 2500,
            'transaction_at' => '2026-10-09T09:00:00+03:00',
            'balance_before' => 200,
            'balance_after' => 2700,
            'transaction_reference' => 'TX-55',
        ];

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($payload)]]]],
                ],
            ], 200),
        ]);

        $extracted = app(GeminiVisionExtractor::class)->extractMany([
            $this->proof('proof-one.png'),
            $this->proof('proof-two.png'),
        ]);

        $this->assertSame(2500, $extracted['amount']);
        $this->assertSame('A-123', $extracted['agent_id']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $parts = $request['contents'][0]['parts'];
            $schema = $request['generationConfig']['responseSchema'];
            return str_ends_with($request->url(), '/models/gemini-3.5-flash-lite:generateContent')
                && $request->hasHeader('x-goog-api-key', 'fake-gemini-test-key')
                && count($parts) === 3
                && $parts[1]['inline_data']['mime_type'] === 'image/png'
                && $parts[2]['inline_data']['mime_type'] === 'image/png'
                && base64_decode($parts[1]['inline_data']['data']) === 'synthetic-png-payload'
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $schema['type'] === 'object'
                && $schema['properties']['agent_id']['type'] === ['string', 'null']
                && $schema['properties']['amount']['type'] === ['number', 'null'];
        });
    }

    public function test_paid_gemini_reports_provider_limit_without_exposing_key(): void
    {
        $this->configureGemini();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'error' => ['message' => 'private provider error'],
            ], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction failed with HTTP 429.');
        app(GeminiVisionExtractor::class)->extract($this->proof('proof.png'));
    }
}
