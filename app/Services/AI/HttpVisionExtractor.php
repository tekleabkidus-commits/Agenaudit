<?php

namespace App\Services\AI;

use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HttpVisionExtractor implements VisionExtractorInterface
{
    public function extract(EvidenceFile $evidence): array
    {
        return $this->extractMany([$evidence]);
    }

    public function extractMany(array $evidences): array
    {
        if (!$evidences) throw new RuntimeException('At least one evidence image is required.');
        $endpoint = config('services.ai.endpoint');
        $key = config('services.ai.api_key');
        if (!$endpoint || !$key) throw new RuntimeException('AI endpoint is not configured.');

        $streams = [];
        try {
            $request = Http::timeout((int) config('services.ai.timeout',45))
                ->acceptJson()->withToken($key);

            foreach ($evidences as $index=>$evidence) {
                if ($evidence->kind !== $evidences[0]->kind) {
                    throw new RuntimeException('Cannot combine evidence of different kinds.');
                }
                $stream = Storage::disk($evidence->disk)->readStream($evidence->path);
                if (!is_resource($stream)) throw new RuntimeException('Evidence file could not be opened.');
                $streams[] = $stream;
                // The original single-file contract is preserved; groups
                // use files[] and require a gateway supporting multi-image input.
                $field = count($evidences) === 1 ? 'file' : 'files[]';
                $request = $request->attach($field,$stream,$evidence->original_name,
                    ['Content-Type'=>$evidence->mime_type]);
            }

            $response=$request->post($endpoint,[
                'kind'=>$evidences[0]->kind->value,
                'schema_version'=>'2026-10-07',
                'model'=>config('services.ai.model'),
                'image_count'=>count($evidences),
            ]);
        } finally {
            foreach ($streams as $stream) fclose($stream);
        }

        if (!$response->successful()) {
            throw new RuntimeException('AI extraction failed with HTTP '.$response->status().'.');
        }
        $json=$response->json();
        if (!is_array($json)) throw new RuntimeException('AI extraction returned invalid JSON.');
        return $json;
    }
}
