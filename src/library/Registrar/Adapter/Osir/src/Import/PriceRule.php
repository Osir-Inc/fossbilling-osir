<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

use Osir\FossBilling\Exception\ValidationException;

/**
 * Turns OSIR's cost into a FOSSBilling selling price: cost + percentage + fixed amount, then
 * rounded up. All arithmetic is in integer cents (the percentage in basis points), so there is
 * no floating-point drift, and the result is never below the cost.
 */
final class PriceRule
{
    /** 1000 %: anything above is almost certainly a typo. */
    public const int MAX_PERCENT_BASIS_POINTS = 100_000;
    /** 1000.00 USD per year. */
    public const int MAX_FIXED_CENTS = 100_000;

    private function __construct(
        public readonly int $percentBasisPoints,
        public readonly int $fixedCents,
        public readonly Rounding $rounding,
    ) {}

    /**
     * @throws ValidationException
     */
    public static function of(int $percentBasisPoints, int $fixedCents, Rounding $rounding): self
    {
        if ($percentBasisPoints < 0 || $percentBasisPoints > self::MAX_PERCENT_BASIS_POINTS) {
            throw new ValidationException('The markup percentage must be between 0 and 1000.');
        }
        if ($fixedCents < 0 || $fixedCents > self::MAX_FIXED_CENTS) {
            throw new ValidationException('The fixed markup must be between 0 and 1000.00.');
        }

        return new self($percentBasisPoints, $fixedCents, $rounding);
    }

    /**
     * Parses the admin form: "20" or "12.5" (percent), "1" or "1.50" (fixed amount), a rounding code.
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $percent, mixed $fixed, mixed $rounding): self
    {
        $roundingCase = is_string($rounding) ? Rounding::tryFrom($rounding) : null;
        if ($roundingCase === null) {
            throw new ValidationException('Unknown rounding option.');
        }

        return self::of(
            self::hundredths($percent, 'The markup percentage must be a number such as 20 or 12.5.'),
            self::hundredths($fixed, 'The fixed markup must be an amount such as 1 or 1.50.'),
            $roundingCase,
        );
    }

    /**
     * @throws \InvalidArgumentException for a non-positive cost (a caller bug, never user input)
     */
    public function sellingCents(int $costCents): int
    {
        if ($costCents <= 0) {
            throw new \InvalidArgumentException('Cost must be positive.');
        }
        // Percentage rounded up to the next cent, so the margin is never smaller than configured.
        $marked = $costCents + intdiv($costCents * $this->percentBasisPoints + 9_999, 10_000) + $this->fixedCents;

        return max($costCents, $this->rounding->apply($marked));
    }

    public function describe(): string
    {
        $parts = [rtrim(rtrim(sprintf('%.2f', $this->percentBasisPoints / 100), '0'), '.') . ' %'];
        if ($this->fixedCents > 0) {
            $parts[] = sprintf('%.2f', $this->fixedCents / 100);
        }

        return implode(' + ', $parts) . match ($this->rounding) {
            Rounding::None => '',
            Rounding::Cents99 => ', rounded up to .99',
            Rounding::Whole => ', rounded up to a whole amount',
        };
    }

    /**
     * "12", "12.5", "12.50" → 1250. Empty means 0. Anything else (negative, exponent, more than
     * two decimals, thousands separators) is refused rather than guessed.
     *
     * @throws ValidationException
     */
    private static function hundredths(mixed $value, string $message): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            throw new ValidationException($message);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
