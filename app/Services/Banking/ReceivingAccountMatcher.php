<?php

namespace App\Services\Banking;

use App\Models\Bank;
use App\Models\Brand;
use App\Models\ReceivingAccount;
use App\Services\SettingsService;
use App\Support\Normalizer;

class ReceivingAccountMatcher
{
    public function __construct(private SettingsService $settings) {}

    public function match(Brand $brand, Bank $toBank, ?string $detectedAccount, ?string $detectedName): ReceivingAccountMatch
    {
        if (!$detectedAccount) return new ReceivingAccountMatch('insufficient', reason: 'Receiver account is not readable.');
        $masked = Normalizer::isMaskedAccount($detectedAccount);
        $requireName = $this->settings->bool('validation.require_receiver_name', true);
        if (($masked || $requireName) && !$detectedName) {
            return new ReceivingAccountMatch('insufficient', reason: 'Receiver name is required to verify this account.');
        }

        $accounts = ReceivingAccount::query()
            ->with('bank')
            ->where('bank_id', $toBank->id)
            ->where('is_active', true)
            ->whereHas('brands', fn ($q) => $q->where('brands.id', $brand->id))
            ->get();

        if ($accounts->isEmpty()) return new ReceivingAccountMatch('no_match', reason: 'No approved receiving account exists for this brand and bank.');

        $minNameSimilarity = $this->settings->float('validation.receiver_name_similarity', 0.90);
        $matches = [];
        foreach ($accounts as $account) {
            $accountNumberMatches = $masked
                ? Normalizer::maskedAccountMatches($detectedAccount, $account->account_number)
                : Normalizer::account($detectedAccount) === $account->normalized_account_number;
            if (!$accountNumberMatches) continue;

            $names = array_filter(array_merge([$account->account_name], $account->name_aliases ?? []));
            $best = 0.0;
            foreach ($names as $name) $best = max($best, Normalizer::nameSimilarity($detectedName, (string) $name));
            if ($detectedName && $best < $minNameSimilarity) continue;
            if ($requireName && !$detectedName) continue;

            $matches[] = [$account, $best ?: 1.0];
        }

        if (count($matches) === 1) {
            [$account, $nameScore] = $matches[0];
            return new ReceivingAccountMatch('matched', $account, $masked ? 'masked_digits+name' : 'full_account+name', $nameScore);
        }
        if (count($matches) > 1) return new ReceivingAccountMatch('ambiguous', reason: 'Visible account details match more than one approved receiving account.');

        return new ReceivingAccountMatch('no_match', reason: 'Detected receiving account/name does not match any approved account for this brand.');
    }
}
