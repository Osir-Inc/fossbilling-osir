<?php

declare(strict_types=1);

namespace Osir\FossBilling\Http;

/**
 * A successful OSIR response with the envelope (if any) already removed.
 */
final class ApiResponse
{
    /**
     * @param array<array-key, mixed> $data the envelope's `data`, or the whole body for unwrapped endpoints
     * @param bool                    $replayed true when OSIR answered from its idempotency store (`Idempotent-Replay: true`)
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly bool $replayed,
        public readonly string $requestId,
    ) {}
}
