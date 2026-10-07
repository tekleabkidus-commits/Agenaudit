<?php

namespace App\Http\Controllers;

use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceFileController extends Controller
{
    public function __invoke(EvidenceFile $evidence): StreamedResponse
    {
        $this->authorize('view', $evidence);
        return Storage::disk($evidence->disk)->response($evidence->path, $evidence->original_name, [
            'Content-Type'=>$evidence->mime_type,
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
