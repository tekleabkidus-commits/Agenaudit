<?php

namespace App\Models;

use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectionRequest extends Model
{
    protected $fillable = ['transaction_id','payment_record_id','evidence_file_id','requested_by','field_name','ai_value','proposed_value','reason','status','reviewed_by','reviewed_at','admin_note'];
    protected function casts(): array { return ['ai_value'=>'json','proposed_value'=>'json','status'=>CorrectionStatus::class,'reviewed_at'=>'datetime']; }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function paymentRecord(): BelongsTo { return $this->belongsTo(PaymentRecord::class); }
    public function evidenceFile(): BelongsTo { return $this->belongsTo(EvidenceFile::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
