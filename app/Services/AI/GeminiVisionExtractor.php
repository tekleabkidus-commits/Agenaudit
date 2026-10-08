<?php

namespace App\Services\AI;

use App\Enums\EvidenceKind;
use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Optional Google Gemini adapter. Do not use Google's unpaid service with
 * confidential financial evidence without explicitly approving its data-use
 * terms; blocked by default in production.
 */
class GeminiVisionExtractor implements VisionExtractorInterface
{
    public function __construct(private OpenAIVisionExtractor $schemaProvider) {}

    public function extract(EvidenceFile $evidence): array
    {
        return $this->extractMany([$evidence]);
    }

    public function extractMany(array $evidences): array
    {
        if (!$evidences) throw new RuntimeException('At least one image is required.');

        if (!config('services.ai.gemini_allow_sensitive_evidence', false)) {
            throw new RuntimeException('Gemini financial evidence uploads are disabled: Google free-tier data-use approval has not been granted. Use a private provider or Admin manual extraction.');
        }

        $key=(string) config('services.ai.gemini_api_key', '');
        $model=(string) config('services.ai.gemini_model','gemini-3.5-flash-lite');
        if ($key==='') throw new RuntimeException('GEMINI_API_KEY is not set.');

        $kind=$evidences[0]->kind;
        $parts=[[
            'text'=>$this->schemaProvider->prompt($kind)
                ."\nReturn one JSON object combining these images as ONE transaction. Cross-check repeated fields. Never sum amounts across proof screenshots of the same transaction. If contradictory, lower critical_confidence.",
        ]];

        foreach ($evidences as $evidence) {
            if ($evidence->kind!==$kind) throw new RuntimeException('Cannot combine different evidence kinds.');
            $bytes=Storage::disk($evidence->disk)->get($evidence->path);
            if ($bytes==='' || $bytes===false) throw new RuntimeException('Could not read evidence image.');
            $parts[]=['inline_data'=>[
                'mime_type'=>$evidence->mime_type,
                'data'=>base64_encode($bytes),
            ]];
        }

        $response=Http::timeout((int)config('services.ai.timeout',45))
            ->acceptJson()
            ->withHeaders(['x-goog-api-key'=>$key])
            ->withoutRedirecting()
            ->post(
                rtrim((string)config('services.ai.gemini_base_url','https://generativelanguage.googleapis.com/v1beta'),'/')
                .'/models/'.rawurlencode($model).':generateContent',
                [
                    'contents'=>[['role'=>'user','parts'=>$parts]],
                    'generationConfig'=>[
                        'responseMimeType'=>'application/json',
                        'responseSchema'=>$this->googleSchema($this->schemaProvider->schema($kind)),
                    ],
                ]
            );
        if (!$response->successful()) {
            throw new RuntimeException('Gemini extraction failed with HTTP '.$response->status().'.');
        }

        $text=(string)data_get($response->json(),'candidates.0.content.parts.0.text','');
        $decoded=json_decode($text,true);
        if (!is_array($decoded) || json_last_error()!==JSON_ERROR_NONE) {
            throw new RuntimeException('Gemini returned invalid structured evidence.');
        }
        return $decoded;
    }

    private function googleSchema(array $schema): array
    {
        if (isset($schema['anyOf'])) {
            $types=array_column($schema['anyOf'],'type');
            if (count($types)===count($schema['anyOf'])) return ['type'=>$types];
        }
        foreach ($schema as $key=>$value) {
            if (is_array($value)) {
                if ($key==='properties') {
                    $schema[$key]=array_map(fn ($child)=>$this->googleSchema($child),$value);
                } elseif ($key==='items') {
                    $schema[$key]=$this->googleSchema($value);
                }
            }
        }
        return $schema;
    }
}
