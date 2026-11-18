<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * All money in this app is a whole number of nanodollars (1 USD = 1,000,000,000 n$).
 *
 * That is the precision Pixelforge bills in ("0.046125000"), so its costs fit
 * exactly: nothing is ever rounded and nothing passes through a float. Amounts
 * only become decimal strings at the edges, when reading Pixelforge's JSON and
 * when handing numbers to the browser.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * Parse an exact decimal dollar amount: "0.046125000" => 46_125_000.
     */
    public static function parse(string $dollars): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d+))?$/', $dollars, $matches) !== 1) {
            throw new InvalidArgumentException("[{$dollars}] is not a decimal dollar amount.");
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';

        // Anything past the ninth decimal is a fraction of a nanodollar.
        // Dropping it would be silent rounding, so refuse instead.
        if (rtrim(substr($fraction, 9), '0') !== '') {
            throw new InvalidArgumentException("[{$dollars}] is more precise than a nanodollar.");
        }

        return (int) $whole * self::NANOS_PER_DOLLAR
            + (int) str_pad(substr($fraction, 0, 9), 9, '0');
    }

    /**
     * Format nanodollars as an exact decimal dollar amount: 46_125_000 => "0.046125000".
     */
    public static function format(int $nanos): string
    {
        return sprintf(
            '%s%d.%09d',
            $nanos < 0 ? '-' : '',
            intdiv(abs($nanos), self::NANOS_PER_DOLLAR),
            abs($nanos) % self::NANOS_PER_DOLLAR,
        );
    }
}
