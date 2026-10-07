<?php

namespace App\Support;

final class Normalizer
{
    public static function transactionId(?string $value): ?string
    {
        if ($value === null) return null;
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($value)));
        return $normalized !== '' ? $normalized : null;
    }

    public static function identifier(?string $value): ?string
    {
        if ($value === null) return null;
        $normalized = strtoupper((string) preg_replace('/\s+/', '', trim($value)));
        return $normalized !== '' ? $normalized : null;
    }

    public static function account(?string $value, bool $keepMask = false): ?string
    {
        if ($value === null) return null;
        $pattern = $keepMask ? '/[^A-Z0-9*X•]/i' : '/[^A-Z0-9]/i';
        $normalized = strtoupper((string) preg_replace($pattern, '', trim($value)));
        return $normalized !== '' ? $normalized : null;
    }

    public static function name(?string $value): ?string
    {
        if ($value === null) return null;
        $normalized = strtoupper((string) preg_replace('/[^\pL\pN]+/u', ' ', trim($value)));
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));
        return $normalized !== '' ? $normalized : null;
    }

    public static function nameSimilarity(?string $a, ?string $b): float
    {
        $a = self::name($a);
        $b = self::name($b);
        if (!$a || !$b) return 0.0;
        if ($a === $b) return 1.0;
        similar_text($a, $b, $percent);
        return round($percent / 100, 4);
    }

    public static function maskedAccountMatches(string $masked, string $full): bool
    {
        $masked = self::account($masked, true) ?? '';
        $full = self::account($full, false) ?? '';
        if ($masked === '' || $full === '') return false;
        if (!preg_match('/[*X•]/u', $masked)) return self::account($masked) === $full;

        // Banks do not always use one mask symbol per hidden digit. Treat each
        // consecutive mask run as one-or-more hidden alphanumeric characters.
        $canonicalMask = preg_replace('/[X•]+/u', '*', $masked);
        $parts = preg_split('/\*+/', (string) $canonicalMask);
        $quotedParts = array_map(fn (string $part) => preg_quote($part, '/'), $parts ?: []);
        $regex = '^'.implode('[A-Z0-9]+', $quotedParts).'$';
        return (bool) preg_match('/'.$regex.'/', $full);
    }

    public static function isMaskedAccount(?string $account): bool
    {
        return $account !== null && (bool) preg_match('/[*X•]/i', $account);
    }
}
