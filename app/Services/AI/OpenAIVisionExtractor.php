<?php

namespace App\Services\AI;

use App\Enums\EvidenceKind;
use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAIVisionExtractor implements VisionExtractorInterface
{
    public function extract(EvidenceFile $evidence): array
    {
        return $this->extractMany([$evidence]);
    }

    public function extractMany(array $evidences): array
    {
        if ($evidences === []) throw new RuntimeException('At least one evidence image is required.');

        $key = (string) config('services.ai.api_key');
        $model = (string) config('services.ai.model');
        $baseUrl = rtrim((string) config('services.ai.base_url', 'https://api.openai.com/v1'), '/');

        if ($key === '' || $model === '') {
            throw new RuntimeException('OpenAI AI driver is not configured. AI_API_KEY and AI_MODEL are required.');
        }

        $kind = $evidences[0]->kind;
        $content = [[
            'type'=>'input_text',
            'text'=>$this->prompt($kind).(count($evidences)>1
                ? "\nThe following images are supplementary views of the SAME agent transaction. Cross-check identity, amount and time across images. If images disagree on a critical value, report low confidence. Never add or double-count amounts from repeated screenshots."
                : ''),
        ]];

        foreach ($evidences as $evidence) {
            if ($evidence->kind !== $kind) throw new RuntimeException('Cannot combine different kinds of evidence.');
            $bytes = Storage::disk($evidence->disk)->get($evidence->path);
            if ($bytes === '' || $bytes === false) throw new RuntimeException('Evidence file could not be read.');
            $mime = in_array($evidence->mime_type,['image/jpeg','image/png','image/webp'],true)
                ? $evidence->mime_type : 'image/jpeg';
            $content[] = [
                'type'=>'input_image',
                'image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes),
                'detail'=>'high',
            ];
        }

        $response = Http::timeout((int) config('services.ai.timeout', 45))
            ->acceptJson()
            ->withToken($key)
            ->post($baseUrl.'/responses', [
                'model'=>$model,
                'input'=>[['role'=>'user','content'=>$content]],
                'text'=>['format'=>[
                    'type'=>'json_schema',
                    'name'=>$kind === EvidenceKind::AgentSystem ? 'agent_system_evidence' : 'bank_payment_evidence',
                    'strict'=>true,
                    'schema'=>$this->schema($kind),
                ]],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('OpenAI extraction failed with HTTP '.$response->status().'.');
        }

        $body = $response->json();
        if (!is_array($body)) throw new RuntimeException('OpenAI extraction returned an invalid response.');
        $text = $this->extractOutputText($body);
        if ($text === null || trim($text) === '') throw new RuntimeException('OpenAI extraction returned no structured output.');
        $decoded = json_decode($text,true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('OpenAI extraction returned malformed structured JSON.');
        }
        return $decoded;
    }

    private function extractOutputText(array $body): ?string
    {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        foreach (($body['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text' && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    public function prompt(EvidenceKind $kind): string
    {
        $common = <<<'TEXT'
You are the evidence-reading component of a financial audit system. Read only information that is visibly present in the uploaded screenshot. Never invent, infer, repair, or guess a value that is not reliably readable. Preserve masked account characters such as * exactly as visible where practical. Amounts must be numeric without currency symbols. transaction_at must be ISO-8601 with timezone when the screenshot explicitly provides enough information; otherwise use the most faithful ISO-like value possible and lower critical confidence. Quality score is overall screenshot readability from 0 to 1. Critical confidence is confidence in the fields required to validate the financial event. If a critical field is unreadable, return null for that field and lower critical_confidence so the system can request a clearer screenshot. Do not identify a brand from visual styling alone when an Agent ID/username can be read more reliably; brand_hint is only a hint. Return only the requested structured data.
TEXT;

        if ($kind === EvidenceKind::AgentSystem) {
            return $common."\nThis is an AGENT SYSTEM screenshot. Extract the agent ID, agent username, optional brand hint, credited/removed/commission amount, transaction timestamp, optional balance before/after, and optional internal transaction reference.";
        }

        return $common."\nThis is a BANK/WALLET PAYMENT screenshot. Extract the institution the money came FROM and the institution it went TO separately, sender account/phone and full visible sender name when present, receiver account/phone and full receiver name, amount, bank transaction/reference ID, transaction timestamp, and visible status. Do not substitute the same bank for both sides unless the screenshot actually shows that.";
    }

    public function schema(EvidenceKind $kind): array
    {
        $nullableString = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $nullableNumber = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];
        $quality = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'critical_confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['score', 'critical_confidence', 'issues'],
        ];

        if ($kind === EvidenceKind::AgentSystem) {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'quality' => $quality,
                    'agent_id' => $nullableString,
                    'agent_username' => $nullableString,
                    'brand_hint' => $nullableString,
                    'amount' => $nullableNumber,
                    'transaction_at' => $nullableString,
                    'balance_before' => $nullableNumber,
                    'balance_after' => $nullableNumber,
                    'transaction_reference' => $nullableString,
                ],
                'required' => ['quality', 'agent_id', 'agent_username', 'brand_hint', 'amount', 'transaction_at', 'balance_before', 'balance_after', 'transaction_reference'],
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'quality' => $quality,
                'from_bank' => $nullableString,
                'to_bank' => $nullableString,
                'sender_account' => $nullableString,
                'sender_name' => $nullableString,
                'receiver_account' => $nullableString,
                'receiver_name' => $nullableString,
                'amount' => $nullableNumber,
                'transaction_id' => $nullableString,
                'transaction_at' => $nullableString,
                'status' => $nullableString,
            ],
            'required' => ['quality', 'from_bank', 'to_bank', 'sender_account', 'sender_name', 'receiver_account', 'receiver_name', 'amount', 'transaction_id', 'transaction_at', 'status'],
        ];
    }
}
