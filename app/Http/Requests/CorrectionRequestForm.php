<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionRequestForm extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isEmployee() ?? false; }
    public function rules(): array
    {
        return [
            'field'=>['required','string','max:64'],
            'proposed_value'=>['required'],
            'reason'=>['required','string','min:5','max:1000'],
            'payment_record_id'=>['nullable','integer','exists:payment_records,id'],
            'evidence_file_id'=>['nullable','integer','exists:evidence_files,id'],
        ];
    }
}
