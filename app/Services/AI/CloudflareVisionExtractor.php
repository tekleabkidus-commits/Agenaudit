<?php

namespace App\Services\AI;

use App\Enums\EvidenceKind;
use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CloudflareVisionExtractor implements VisionExtractorInterface
{
    public function __construct(private OpenAIVisionExtractor $schemas) {}

    public function extract(EvidenceFile $evidence): array
    {
        return $this->extractMany([$evidence]);
    }

    public function extractMany(array $evidences): array
    {
        if (!$evidences || count($evidences)>5) {
            throw new RuntimeException('Agent proof extraction requires between one and five images.');
        }

        $account=(string)config('services.ai.cloudflare_account_id','');
        $token=(string)config('services.ai.cloudflare_api_token','');
        $model=(string)config('services.ai.cloudflare_model','@cf/meta/llama-3.2-11b-vision-instruct');

        if ($account==='' || $token==='') {
            throw new RuntimeException('Cloudflare Workers AI needs CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_AI_API_TOKEN.');
        }

        $kind=$evidences[0]->kind;
        foreach ($evidences as $file) {
            if ($file->kind !== $kind) {
                throw new RuntimeException('Different evidence kinds cannot be combined.');
            }
        }

        $image=$this->makeImage($evidences);
        $prompt=$this->schemas->prompt($kind)
            ."\nReturn exactly one JSON object matching this schema: "
            .json_encode($this->schemas->schema($kind),JSON_UNESCAPED_SLASHES)
            ."\nSupplementary images show ONE agent transaction, not several. Cross-check repeated identity, amount, date and balances; never add repeated amounts. If they disagree lower confidence and do not guess.";

        $response=Http::acceptJson()
            ->withToken($token)
            ->withoutRedirecting()
            ->timeout((int)config('services.ai.timeout',45))
            ->post(
                'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($account).'/ai/run/'.$model,
                [
                    'messages'=>[
                        ['role'=>'system','content'=>'Read screenshot evidence accurately. Return strict JSON only.'],
                        ['role'=>'user','content'=>$prompt],
                    ],
                    'image'=>$image,
                    'max_tokens'=>1600,
                    'temperature'=>0,
                ]
            );

        if (!$response->successful() || !data_get($response->json(),'success',false)) {
            throw new RuntimeException('Cloudflare Workers AI extraction failed with HTTP '.$response->status().'. Check the daily free allocation, token permissions and Meta model license acceptance.');
        }

        $text=(string)data_get($response->json(),'result.response','');
        $text=trim($text);
        if (str_starts_with($text,'```')) {
            $text=(string)preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i','',$text);
        }
        $decoded=json_decode($text,true);
        if (!is_array($decoded) || json_last_error()!==JSON_ERROR_NONE) {
            throw new RuntimeException('Cloudflare Workers AI returned invalid structured JSON; Admin review is required.');
        }

        return $decoded;
    }

    /**
     * The Cloudflare Llama Vision API accepts one image. Combine supplementary
     * proof images in memory without changing or persisting the originals.
     * Never combine bank receipts: these are processed individually.
     */
    private function makeImage(array $evidences): string
    {
        if (count($evidences)===1) {
            $e=$evidences[0];
            $bytes=Storage::disk($e->disk)->get($e->path);
            if (!$bytes) throw new RuntimeException('Evidence image is missing.');
            return 'data:'.$e->mime_type.';base64,'.base64_encode($bytes);
        }

        if ($evidences[0]->kind !== EvidenceKind::AgentSystem) {
            throw new RuntimeException('Only agent-system proof images may be combined.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD PHP extension is required for private multi-image collage creation.');
        }

        $images=[];
        $width=0;
        $height=0;
        try {
            foreach ($evidences as $e) {
                $bytes=Storage::disk($e->disk)->get($e->path);
                if (!$bytes) throw new RuntimeException('Evidence image is missing.');
                $img=@imagecreatefromstring($bytes);
                if (!$img) throw new RuntimeException('An evidence image could not be decoded.');
                $w=imagesx($img);
                $h=imagesy($img);
                $targetW=min($w,1600);
                $targetH=max(1,(int)round($h*$targetW/$w));
                $images[]=['resource'=>$img,'width'=>$targetW,'height'=>$targetH];
                $width=max($width,$targetW);
                $height+=$targetH+34;
            }

            if ($width*$height > 20000000) {
                throw new RuntimeException('Agent proof collage exceeds safe image size. Upload smaller or fewer screenshots.');
            }

            $canvas=imagecreatetruecolor($width,$height);
            if (!$canvas) throw new RuntimeException('Could not allocate proof collage.');
            try {
                $white=imagecolorallocate($canvas,255,255,255);
                $black=imagecolorallocate($canvas,0,0,0);
                imagefill($canvas,0,0,$white);
                $y=0;

                foreach ($images as $index=>$image) {
                    imagestring($canvas,3,8,$y+8,'PROOF '.($index+1),$black);
                    $y+=34;
                    imagecopyresampled($canvas,$image['resource'],0,$y,0,0,
                        $image['width'],$image['height'],
                        imagesx($image['resource']),imagesy($image['resource']));
                    $y+=$image['height'];
                }

                ob_start();
                imagepng($canvas);
                $bytes=ob_get_clean();
                if (!is_string($bytes) || $bytes==='') throw new RuntimeException('Could not encode proof collage.');
                return 'data:image/png;base64,'.base64_encode($bytes);
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            foreach ($images as $image) imagedestroy($image['resource']);
        }
    }
}
