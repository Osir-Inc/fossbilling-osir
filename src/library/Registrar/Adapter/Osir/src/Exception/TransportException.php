<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * No usable HTTP response was received (DNS, TCP, TLS, timeout, oversized response).
 *
 * For state-changing requests the outcome at OSIR is UNKNOWN: the request may have been
 * processed before the connection dropped. {@see self::isOutcomeUnknown()} is true in that case;
 * callers must not assume failure. Repeating the call with the SAME Idempotency-Key is safe.
 */
final class TransportException extends OsirException
{
    public function __construct(
        string $reason,
        private readonly string $requestId,
        private readonly bool $outcomeUnknown,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            'Could not reach OSIR (:reason). Reference :reference.',
            [':reason' => $reason, ':reference' => $requestId],
            0,
            $previous,
        );
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
