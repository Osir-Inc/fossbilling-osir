<?php

declare(strict_types=1);

namespace Osir\FossBilling\Http;

use Osir\FossBilling\Exception\ApiErrorKind;

/**
 * Decides whether and when to repeat a failed request, within an overall wall-clock deadline.
 *
 * Rules:
 *   - Only retry-safe requests (GET, or POST carrying an Idempotency-Key) are repeated after a
 *     transport failure, a 502/503/504, or a 409 REQUEST_IN_PROGRESS.
 *   - Any request may be repeated after a 429: OSIR's rate limiter rejects before the endpoint runs.
 *   - The deadline covers the whole call — time spent inside attempts AND waiting between them —
 *     short in a web request (a person is waiting), longer in CLI/cron. {@see ApiClient} also caps
 *     each attempt's own timeout to what is left of it.
 */
final class RetryPolicy
{
    private const array RETRYABLE_SERVER_STATUSES = [502, 503, 504];
    /** Never start an attempt with less time than this left; it would only time out. */
    private const float MIN_ATTEMPT_SECONDS = 5.0;

    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly float $deadlineSeconds = 90.0,
        private readonly float $baseDelaySeconds = 0.5,
    ) {}

    public static function forCurrentSapi(): self
    {
        return PHP_SAPI === 'cli' ? new self(4, 300.0, 1.0) : new self(3, 90.0, 0.5);
    }

    public function deadlineSeconds(): float
    {
        return $this->deadlineSeconds;
    }

    /**
     * @param float $elapsed seconds since the first attempt started
     *
     * @return float|null seconds to wait before the next attempt, or null to give up
     */
    public function delayAfterError(ApiRequest $request, int $attempt, float $elapsed, ?ApiErrorKind $kind, ?int $status, ?int $retryAfter): ?float
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }

        $retryable = match (true) {
            $kind === ApiErrorKind::RateLimited => true,
            !$request->isRetrySafe() => false,
            $kind === null => true, // transport failure
            $kind === ApiErrorKind::InProgress => true,
            $kind === ApiErrorKind::Server => in_array($status, self::RETRYABLE_SERVER_STATUSES, true),
            default => false,
        };
        if (!$retryable) {
            return null;
        }

        $delay = $retryAfter !== null && $retryAfter > 0
            ? (float) $retryAfter
            : $this->baseDelaySeconds * (2 ** ($attempt - 1)) + random_int(0, 250) / 1000;

        return $elapsed + $delay + self::MIN_ATTEMPT_SECONDS <= $this->deadlineSeconds ? $delay : null;
    }
}
