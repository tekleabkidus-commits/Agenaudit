<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadEvidenceRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user(); }
    public function rules(): array
    {
        $max = (int) config('agent_audit.evidence.max_kb', 12288);
        return [
            'screenshot'=>['nullable','file','mimetypes:image/jpeg,image/png,image/webp','max:'.$max],
            'screenshots'=>['nullable','array','max:'.config('agent_audit.evidence.max_bank_screenshots_per_transaction',12)],
            'screenshots.*'=>['file','mimetypes:image/jpeg,image/png,image/webp','max:'.$max],
        ];
    }
}
