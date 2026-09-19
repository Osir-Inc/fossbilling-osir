<?php

declare(strict_types=1);

namespace Osir\FossBilling\Config;

/**
 * Holds a credential so it cannot leak through string conversion, var_dump/print_r/var_export,
 * `(array)` casts, serialisation, or a stack trace that prints arguments.
 *
 * The value is deliberately NOT stored in a property: every dump mechanism in PHP (var_export
 * and array casts included, which ignore __debugInfo) can read private properties. It lives in a
 * static WeakMap keyed by the instance instead, and is released with the object. The raw value is
 * reachable only through {@see self::reveal()}, which the adapter calls in exactly one place:
 * when the `X-API-Key` header is built.
 */
final class Secret implements \Stringable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? throw new \LogicException('Secret is no longer available.');
    }

    /**
     * Hint for diagnostics: the type prefix plus two characters and the length, e.g.
     * "osir_live_ab…(42 chars)". Never show more than this.
     */
    public function hint(): string
    {
        $value = $this->reveal();

        return substr($value, 0, 12) . '…(' . strlen($value) . ' chars)';
    }

    public function __toString(): string
    {
        return '[secret]';
    }

    /** Cloning is refused: a clone would have no entry in the map, and nothing needs a copy. */
    private function __clone() {}

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Secrets must not be serialised.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Secrets must not be unserialised.');
    }
}
