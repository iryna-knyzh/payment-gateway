<?php

declare(strict_types=1);

namespace App\Payment\Money;

/**
 * Converts between integer minor units (cents, kopecks) and decimal strings ("10.50").
 *
 * Money is never represented as float in this project: 0.1 + 0.2 !== 0.3.
 * Internally amounts are integers in minor units; decimal strings exist only
 * at the boundary with providers whose API expects major units.
 */
final class MinorUnits
{
    /** ISO 4217 exponents that differ from the default of 2. */
    private const EXPONENTS = [
        'JPY' => 0,
        'KRW' => 0,
        'VND' => 0,
        'BHD' => 3,
        'KWD' => 3,
        'TND' => 3,
    ];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /** 1050, "UAH" -> "10.50";  500, "JPY" -> "500" */
    public static function toDecimalString(int $minor, string $currency): string
    {
        if ($minor < 0) {
            throw new \InvalidArgumentException('Amount must not be negative.');
        }

        $exponent = self::exponent($currency);

        if (0 === $exponent) {
            return (string) $minor;
        }

        $digits = str_pad((string) $minor, $exponent + 1, '0', \STR_PAD_LEFT);

        return substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    /** "10.50", "UAH" -> 1050;  "10.5" -> 1050;  "10" -> 1000 */
    public static function fromDecimalString(string $decimal, string $currency): int
    {
        $exponent = self::exponent($currency);

        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
            throw new \InvalidArgumentException(\sprintf('Invalid decimal amount "%s".', $decimal));
        }

        $fraction = $m[2] ?? '';

        if (\strlen($fraction) > $exponent) {
            throw new \InvalidArgumentException(\sprintf('Amount "%s" has more than %d decimal places for %s.', $decimal, $exponent, $currency));
        }

        $minor = ltrim($m[1].str_pad($fraction, $exponent, '0'), '0');

        if ('' === $minor) {
            return 0;
        }

        if (\strlen($minor) > 18) {
            throw new \InvalidArgumentException(\sprintf('Amount "%s" is too large.', $decimal));
        }

        return (int) $minor;
    }
}
