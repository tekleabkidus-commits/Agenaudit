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
        $endpoint = config('services.ai.endpoint');
        $key = config('services.ai.api_key');
        if (!$endpoint || !$key) throw new RuntimeException('AI endpoint is not configured.');

        $stream = Storage::disk($evidence->disk)->readStream($evidence->path);
        if (!is_resource($stream)) throw new RuntimeException('Evidence file could not be opened.');

        try {
            $response = Http::timeout((int) config('services.ai.timeout', 45))
                ->acceptJson()
                ->withToken($key)
                ->attach('file', $stream, $evidence->original_name, ['Content-Type'=>$evidence->mime_type])
                ->post($endpoint, [
                    'kind'=>$evidence->kind->value,
                    'schema_version'=>'2026-10-07',
                    'model'=>config('services.ai.model'),
                ]);
        } finally {
            fclose($stream);
        }

        if (!$response->successful()) {
            throw new RuntimeException('AI extraction failed with HTTP '.$response->status().'.');
        }

        $json = $response->json();
        if (!is_array($json)) throw new RuntimeException('AI extraction returned invalid JSON.');
        return $json;
    }
}
