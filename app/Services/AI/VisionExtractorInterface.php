<?php

namespace App\Services\AI;

use App\Models\EvidenceFile;

interface VisionExtractorInterface
{
    /** Read a single bank receipt or agent proof. */
    public function extract(EvidenceFile $evidence): array;

    /**
     * Read several images of ONE agent-system transaction together.
     * Every image is supplementary evidence; never sum duplicated amounts.
     *
     * @param array<int, EvidenceFile> $evidences
     */
    public function extractMany(array $evidences): array;
}
