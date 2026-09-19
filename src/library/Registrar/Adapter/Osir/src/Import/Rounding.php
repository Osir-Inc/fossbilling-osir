<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

/**
 * How a selling price is rounded after the markup. Rounding only ever goes UP, so it can never
 * push a price below the cost it was calculated from.
 */
enum Rounding: string
{
    /** Keep the exact cents. */
    case None = 'none';
    /** Up to the next price ending in .99 (12.10 → 12.99, 12.99 → 12.99). */
    case Cents99 = '99';
    /** Up to the next whole amount (12.10 → 13.00, 12.00 → 12.00). */
    case Whole = 'whole';

    public function apply(int $cents): int
    {
        return match ($this) {
            self::None => $cents,
            self::Cents99 => $cents % 100 === 99 ? $cents : intdiv($cents, 100) * 100 + 99,
            self::Whole => intdiv($cents + 99, 100) * 100,
        };
    }
}
