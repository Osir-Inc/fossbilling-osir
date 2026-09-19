<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * OSIR answered, but not with success.
 *
 * `upstreamMessage` is OSIR's own error text, already sanitised (control characters stripped,
 * length capped, secrets redacted). It can contain account details (for example the balance in
 * an insufficient-funds message), so it is written to the administrator log only and is NEVER
 * shown to FOSSBilling clients. Client-facing wording comes from {@see ApiErrorKind}.
 */
final class ApiException extends OsirException
{
    public function __construct(
        private readonly ApiErrorKind $kind,
        private readonly int $httpStatus,
        private readonly ?string $errorCode,
        private readonly ?string $upstreamMessage,
        private readonly string $requestId,
        private readonly ?int $retryAfterSeconds = null,
        private readonly bool $replayed = false,
    ) {
        parent::__construct(
            'OSIR request failed (:kind, HTTP :status, reference :reference).',
            [':kind' => $kind->value, ':status' => (string) $httpStatus, ':reference' => $requestId],
            $httpStatus,
        );
    }

    public function getKind(): ApiErrorKind
    {
        return $this->kind;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getUpstreamMessage(): ?string
    {
        return $this->upstreamMessage;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * True when OSIR answered from its idempotency store (`Idempotent-Replay: true`): the error is
     * a stored outcome of an EARLIER request with the same key, not a fresh failure.
     */
    public function isReplayed(): bool
    {
        return $this->replayed;
    }
}
