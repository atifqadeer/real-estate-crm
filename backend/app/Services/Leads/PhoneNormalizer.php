<?php
namespace App\Services\Leads;

/**
 * Normalizes phone numbers to E.164. Pakistan is the default market:
 * 03XX-XXXXXXX and 92XXXXXXXXXX both become +92XXXXXXXXXX.
 * Returns null when the number cannot be made plausible.
 */
class PhoneNormalizer
{
    public static function normalize(?string $raw, string $defaultCountry = '92'): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) return null;

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, $defaultCountry) && strlen($digits) >= 11) {
            $candidate = '+' . $digits;
        } elseif (str_starts_with($digits, '0')) {
            $candidate = '+' . $defaultCountry . substr($digits, 1);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $candidate = '+' . $defaultCountry . $digits;
        } else {
            $candidate = '+' . $digits;
        }

        // E.164 sanity: 8..15 digits after '+'.
        $len = strlen($candidate) - 1;
        return ($len >= 8 && $len <= 15) ? $candidate : null;
    }

    public static function normalizeEmail(?string $raw): ?string
    {
        if (!$raw) return null;
        $email = strtolower(trim($raw));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
