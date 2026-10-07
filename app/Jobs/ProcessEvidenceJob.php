<?php

namespace App\Jobs;

use App\Models\EvidenceFile;
use App\Services\Transactions\EvidenceProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEvidenceJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 90;

    public function __construct(public int $evidenceId) { $this->onQueue('evidence'); }

    public function handle(EvidenceProcessor $processor): void
    {
        if ($evidence = EvidenceFile::find($this->evidenceId)) $processor->process($evidence);
    }
}
