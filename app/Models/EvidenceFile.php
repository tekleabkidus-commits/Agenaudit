<?php

namespace App\Models;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EvidenceFile extends Model
{
    protected $fillable = ['transaction_id','superseded_by_id','kind','sequence','disk','path','original_name','mime_type','size_bytes','sha256','status','quality_score','critical_confidence','extracted','raw_ai_response','failure_reason'];
    protected function casts(): array
    {
        return ['kind'=>EvidenceKind::class,'status'=>EvidenceStatus::class,'quality_score'=>'float','critical_confidence'=>'float','extracted'=>'array','raw_ai_response'=>'array'];
    }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function paymentRecord(): HasOne { return $this->hasOne(PaymentRecord::class); }
    public function supersededBy(): BelongsTo { return $this->belongsTo(EvidenceFile::class, 'superseded_by_id'); }
}
