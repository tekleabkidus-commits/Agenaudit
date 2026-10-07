<?php

namespace App\Services\Integrations;

use App\Models\PaymentRecord;
use App\Services\SettingsService;
use App\Support\Normalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckEtClient
{
    public function __construct(private SettingsService $settings) {}

    public function enabledFor(PaymentRecord $payment): bool
    {
        return $this->settings->bool('check_et.enabled', (bool) config('services.check_et.enabled'))
            && filled(config('services.check_et.api_key'))
            && $payment->toBank?->check_et_enabled
            && filled($payment->toBank?->check_et_code);
    }

    /** @return array<string,mixed> */
    public function buildPayload(PaymentRecord $payment): array
    {
        $bank = $payment->toBank;
        $payload = [
            'bank' => $bank?->check_et_code ?: strtolower((string) $bank?->code),
            'transaction_number' => $payment->transaction_id_raw,
        ];

        if ($bank?->check_et_requires_account) {
            $source = $bank->check_et_account_source ?: 'receiving_account';

            $payload['account_number'] = match ($source) {
                'sender_account' => $payment->sender_account,
                'none' => null,
                default => $payment->receivingAccount?->account_number,
            };
        }

        // Deliberately no origin/domain/brand/agent/employee/purpose/amount fields.
        return array_filter($payload, fn ($v) => $v !== null && $v !== '');
    }

    public function verify(PaymentRecord $payment): CheckEtResult
    {
        if (!$this->enabledFor($payment)) return new CheckEtResult('disabled', [], []);

        $payload = $this->buildPayload($payment);
        $base = rtrim((string) config('services.check_et.base_url', 'https://api.check.et'), '/');

        try {
            $response = Http::timeout((int) config('services.check_et.timeout', 8))
                ->acceptJson()
                ->withToken((string) config('services.check_et.api_key'))
                ->withHeaders(['Content-Type'=>'application/json'])
                ->withoutRedirecting()
                ->post($base.'/api/v1/verify', $payload);
        } catch (ConnectionException $e) {
            return new CheckEtResult('unavailable', [], array_keys($payload), $e->getMessage());
        } catch (Throwable $e) {
            return new CheckEtResult('unavailable', [], array_keys($payload), $e->getMessage());
        }

        $json = is_array($response->json()) ? $response->json() : [];
        if (!$response->successful()) return new CheckEtResult('unavailable', $json, array_keys($payload), 'HTTP '.$response->status());

        $success = (bool) data_get($json, 'success', false);
        $exists = (bool) data_get($json, 'exists', $success);
        if (!$success || !$exists) return new CheckEtResult('failed', $json, array_keys($payload), data_get($json, 'message', 'Transaction not verified.'));

        $officialAmount = data_get($json, 'data.receipt.amount');
        if ($officialAmount !== null && abs((float) $officialAmount - (float) $payment->amount) > 0.009) {
            return new CheckEtResult('failed', $json, array_keys($payload), 'Verified bank amount does not match the screenshot amount.');
        }

        $officialReceiver = data_get($json, 'data.receipt.receiver_name');
        if ($officialReceiver && $payment->receiver_name && Normalizer::nameSimilarity($officialReceiver, $payment->receiver_name) < 0.80) {
            return new CheckEtResult('failed', $json, array_keys($payload), 'Verified receiver name differs from screenshot receiver.');
        }

        return new CheckEtResult('passed', $json, array_keys($payload));
    }
}
