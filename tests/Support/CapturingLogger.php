<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Support;

/**
 * Stands in for FOSSBilling's logger. Records every call and FAILS if called with more than one
 * argument, because Box_Log would run vsprintf() over the extra arguments.
 */
final class CapturingLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $lines = [];

    public function error(string ...$args): void
    {
        $this->record('error', $args);
    }

    public function warning(string ...$args): void
    {
        $this->record('warning', $args);
    }

    public function info(string ...$args): void
    {
        $this->record('info', $args);
    }

    public function debug(string ...$args): void
    {
        $this->record('debug', $args);
    }

    public function all(): string
    {
        return implode("\n", array_map(static fn(array $l): string => $l['level'] . ': ' . $l['message'], $this->lines));
    }

    /** @param array<array-key, string> $args */
    private function record(string $level, array $args): void
    {
        if (count($args) !== 1) {
            throw new \LogicException('Logger must be called with exactly one pre-formatted string.');
        }
        $this->lines[] = ['level' => $level, 'message' => array_values($args)[0]];
    }
}
