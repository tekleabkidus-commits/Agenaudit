<?php

namespace App\Services\Transactions;

use App\Enums\EvidenceKind;
use App\Enums\EvidenceStatus;
use App\Models\EvidenceFile;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenceStorageService
{
    public function store(Transaction $transaction, UploadedFile $file, EvidenceKind $kind): EvidenceFile
    {
        $disk = config('agent_audit.evidence.disk', 'private');
        $sha = hash_file('sha256', $file->getRealPath());
        $sequence = (int) $transaction->evidenceFiles()->where('kind', $kind->value)->max('sequence') + 1;
        $extension = strtolower($file->guessExtension() ?: 'bin');
        $path = 'evidence/'.$transaction->reference.'/'.$kind->value.'/'.Str::uuid().'.'.$extension;
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), ['visibility'=>'private']);

        return EvidenceFile::create([
            'transaction_id'=>$transaction->id,'kind'=>$kind,'sequence'=>$sequence,'disk'=>$disk,'path'=>$path,
            'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType() ?: 'application/octet-stream',
            'size_bytes'=>$file->getSize(),'sha256'=>$sha,'status'=>EvidenceStatus::Queued,
        ]);
    }
}
