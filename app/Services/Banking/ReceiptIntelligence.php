<?php

namespace App\Services\Banking;

/**
 * Conservative bank and wallet receipt intelligence. The vision model supplies
 * observed markers; server-side rules check their combination. Template
 * recognition is NOT proof of settlement or of the receiving account.
 *
 * These patterns are descriptions of user-provided examples, never copies
 * of customers' screenshots or identifying data.
 */
class ReceiptIntelligence
{
    private const FAMILIES = [
        'telebirr_pdf' => ['ethio_telecom_header', 'telebirr_payment_mode'],
        'telebirr_green_success' => ['green_success_check', 'dj_reference', 'transaction_to_label'],
        'telebirr_blue_success' => ['blue_payment_success', 'payment_method_label', 'payment_qr_code', 'dj_reference'],
        'telebirr_miniapp' => ['miniapp_storefront_icon', 'transaction_detail_heading', 'get_invoice_link', 'dj_reference'],
    ];

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $family = strtolower(trim((string) ($payload['receipt_template'] ?? '')));
        $features = array_values(array_unique(array_filter(
            array_map(fn ($v) => strtolower(trim((string)$v)), (array) ($payload['visual_indicators'] ?? []))
        )));
        $templateMatch = false;

        if (isset(self::FAMILIES[$family]) && !in_array('explicit_other_issuer', $features, true)) {
            $required = self::FAMILIES[$family];
            $templateMatch = count(array_intersect($required, $features)) === count($required);

            // The PDF's issuer header + payment mode establish origin. For
            // green screens require at least one additional distinctive clue.
            if ($family === 'telebirr_green_success') {
                $extra = ['zemen_gebeya_banner', 'green_qr_label', 'download_share_bar', 'telebirr_receipt_url'];
                $templateMatch = $templateMatch
                    && count(array_intersect($extra, $features)) >= 2;
            }

            // Generic transaction IDs alone prove nothing; check it visually.
            if ($family !== 'telebirr_pdf' && !preg_match('/^DJ[A-Z0-9]{7,}$/i', trim((string)($payload['transaction_id'] ?? '')))) {
                $templateMatch = false;
            }
        }

        $from = trim((string) ($payload['from_bank'] ?? ''));
        $explicitSource = ($payload['from_bank_label_visible'] ?? null) === true;
        $hasOtherIssuer = $from !== '' && !in_array(strtolower($from), [
            'telebirr', 'tele birr', 'ethiotelecom telebirr', 'ethio telecom telebirr',
        ], true);

        // An AI-inferred conflicting bank name is not a visible issuer.
        // Force review instead of allowing an advertisement or screenshot
        // design to determine the institution.
        if (!$explicitSource && $hasOtherIssuer) {
            $payload['from_bank'] = null;
            $templateMatch = false;
        }

        $sourceMethod = 'unconfirmed';
        if ($explicitSource && $from !== '') {
            $sourceMethod = 'visible_issuer';
        } elseif ($templateMatch && !$hasOtherIssuer) {
            $payload['from_bank'] = 'Telebirr';
            $sourceMethod = 'receipt_template';
        } elseif (!$explicitSource && !$hasOtherIssuer && stripos($from, 'telebirr') !== false) {
            // A model's unevidenced guess is not a supported institution.
            $payload['from_bank'] = null;
        }

        // The absence of a bank label must NEVER imply Telebirr-to-Telebirr.
        // Only explicit wallet-to-wallet wording plus a visible destination
        // number can establish a Telebirr receiving-wallet candidate.
        $type = strtolower(trim((string)($payload['transfer_type'] ?? 'unknown')));
        $to = trim((string)($payload['to_bank'] ?? ''));
        $recipient = preg_replace('/\D+/', '', (string)($payload['receiver_account'] ?? ''));
        if ($templateMatch && $type === 'wallet_to_wallet' && $to === ''
            && in_array(strlen($recipient), [9,10,12,13], true)) {
            $payload['to_bank'] = 'Telebirr';
        }

        // Amount and fee handling is BANK-AGNOSTIC. Recognition of an issuer
        // and reconciliation of money transferred are independent operations.
        $raw = $this->positiveAmount($payload['amount'] ?? null);
        $settled = $this->positiveAmount($payload['settled_amount'] ?? null);
        $debited = $this->positiveAmount($payload['total_debited'] ?? null);
        $fee = $this->nonNegativeAmount($payload['service_fee'] ?? null);
        $vat = $this->nonNegativeAmount($payload['fee_vat'] ?? null);
        $otherFees = $this->nonNegativeAmount($payload['other_fees'] ?? null);
        $statedTotalFees = $this->nonNegativeAmount($payload['total_fees'] ?? null);
        $role = strtolower(trim((string)($payload['amount_role'] ?? 'unknown')));
        $hasAmountClassification = array_key_exists('amount_role', $payload)
            || array_key_exists('settled_amount', $payload)
            || array_key_exists('total_debited', $payload);

        $components = array_values(array_filter([$fee, $vat, $otherFees], fn ($v) => $v !== null));
        $componentSum = $components === [] ? null : round(array_sum($components), 2);
        // A stated total fee includes its components. Never add it to them.
        $totalFees = $statedTotalFees ?? $componentSum;
        $amountSource = 'legacy_screenshot_amount';
        $amountNeedsReview = false;

        if ($statedTotalFees !== null && $componentSum !== null
            && $componentSum > $statedTotalFees + 0.009) {
            $amountNeedsReview = true;
        }

        if ($settled !== null) {
            $payload['amount'] = $settled;
            $amountSource = 'explicit_settled_amount';
        } elseif ($role === 'transfer_amount' && $raw !== null) {
            // The visible amount is explicitly TRANSFERRED or RECEIVED.
            // A separately itemized sender fee is irrelevant to that amount.
            $payload['amount'] = $raw;
            $amountSource = 'explicit_transfer_amount';
        } elseif ($role === 'total_debit') {
            // Only subtract fees actually shown on this particular receipt.
            // Absent fee information means UNKNOWN, never zero.
            $actualDebit = $debited ?? $raw;
            if ($actualDebit !== null && $totalFees !== null && $actualDebit > $totalFees
                && ($raw === null || $debited === null || abs($raw - $debited) <= 0.009)) {
                $payload['amount'] = round($actualDebit - $totalFees, 2);
                $amountSource = 'debit_minus_explicit_fees';
            } else {
                $amountNeedsReview = true;
                $amountSource = 'unverified_total_debit';
            }
        } elseif ($hasAmountClassification) {
            // All current vision drivers return amount_role; if unreadable,
            // the screenshot value cannot be counted as a transfer amount.
            // Check.et may later independently supply the verified amount.
            $amountNeedsReview = true;
            $amountSource = 'unconfirmed_amount_role';
        }

        if ($settled !== null && $debited !== null) {
            if ($debited + 0.009 < $settled) {
                $amountNeedsReview = true;
            } elseif ($totalFees !== null && abs($debited - $settled - $totalFees) > 0.009) {
                $amountNeedsReview = true;
            }
        } elseif ($role === 'transfer_amount' && $raw !== null && $debited !== null) {
            if ($debited + 0.009 < $raw || ($totalFees !== null && abs($debited - $raw - $totalFees) > 0.009)) {
                $amountNeedsReview = true;
            }
        }

        $payload['_receipt_intelligence'] = [
            'family' => $templateMatch ? $family : 'unconfirmed',
            'source_method' => $sourceMethod,
            'indicators' => $features,
            'amount_source' => $amountSource,
            'amount_needs_review' => $amountNeedsReview,
            'amount_role' => $role,
            'settled_amount' => $settled,
            'total_debited' => $debited,
            'service_fee' => $fee,
            'fee_vat' => $vat,
            'other_fees' => $otherFees,
            'total_fees' => $totalFees,
        ];

        return $payload;
    }

    private function positiveAmount(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $result = round(abs((float)$value), 2);
        return $result > 0 ? $result : null;
    }

    private function nonNegativeAmount(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $result = round((float)$value, 2);
        return $result >= 0 ? $result : null;
    }
}
