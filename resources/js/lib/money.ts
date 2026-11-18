import type { Money } from '@/types';

/*
 * Money arrives from the server as exact decimal strings ("0.072312500") and
 * is only ever formatted here. It is never turned into a JavaScript number,
 * so nothing is lost to floating point.
 *
 * Amounts are stored to a billionth of a dollar but shown to four decimals.
 * Costs round up and balances round down, so the screen never makes
 * something look cheaper, or an account look richer, than it is. The exact
 * figure is always available through formatExact.
 */

const NANOS_PER_DOLLAR = 1_000_000_000n;
const DISPLAY_DECIMALS = 4;

function toNanos(amount: Money): bigint {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');

    const nanos =
        BigInt(whole) * NANOS_PER_DOLLAR +
        BigInt(fraction.padEnd(9, '0').slice(0, 9));

    return negative ? -nanos : nanos;
}

/**
 * Express nanodollars in units of 10^-decimals dollars, rounding towards
 * positive infinity ("up") or negative infinity ("down").
 */
function toUnits(nanos: bigint, decimals: number, rounding: 'up' | 'down') {
    const unit = 10n ** BigInt(9 - decimals);
    const quotient = nanos / unit; // BigInt division drops the remainder
    const remainder = nanos % unit;

    if (remainder > 0n && rounding === 'up') {
        return quotient + 1n;
    }

    if (remainder < 0n && rounding === 'down') {
        return quotient - 1n;
    }

    return quotient;
}

/**
 * Write a whole number of 10^-decimals dollars as "$1,234.5678", keeping at
 * least two decimals and dropping trailing zeros beyond them.
 */
function render(units: bigint, decimals: number): string {
    const digits = (units < 0n ? -units : units)
        .toString()
        .padStart(decimals + 1, '0');

    const whole = BigInt(digits.slice(0, -decimals)).toLocaleString('en-US');
    const fraction = digits.slice(-decimals).replace(/0+$/, '').padEnd(2, '0');

    return `${units < 0n ? '-' : ''}$${whole}.${fraction}`;
}

/** What something costs: rounded up, so a cost never shows as less than it is. */
export function formatCost(amount: Money): string {
    return render(
        toUnits(toNanos(amount), DISPLAY_DECIMALS, 'up'),
        DISPLAY_DECIMALS,
    );
}

/** What someone has: rounded down, so a balance never shows as more than it is. */
export function formatBalance(amount: Money): string {
    return render(
        toUnits(toNanos(amount), DISPLAY_DECIMALS, 'down'),
        DISPLAY_DECIMALS,
    );
}

/** The exact amount, with every decimal that isn't a trailing zero. */
export function formatExact(amount: Money): string {
    return render(toNanos(amount), 9);
}
