<?php

namespace App\Services\AI;

use App\Models\EvidenceFile;

interface VisionExtractorInterface
{
    /**
     * Returns a normalized extraction payload documented in AI_GATEWAY_CONTRACT.md.
     */
    public function extract(EvidenceFile $evidence): array;
}
