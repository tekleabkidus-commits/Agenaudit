<?php

namespace App\Services\Banking;

use App\Models\Bank;
use App\Support\Normalizer;

class BankResolver
{
    public function resolve(?string $raw): ?Bank
    {
        if (!$raw) return null;
        $needle = Normalizer::name($raw);
        return Bank::query()->where('is_active', true)->get()->first(function (Bank $bank) use ($needle) {
            $candidates = array_filter(array_merge([$bank->code, $bank->name], $bank->aliases ?? []));
            foreach ($candidates as $candidate) {
                if (Normalizer::name((string) $candidate) === $needle || Normalizer::identifier((string) $candidate) === Normalizer::identifier($needle)) return true;
            }
            return false;
        });
    }
}
