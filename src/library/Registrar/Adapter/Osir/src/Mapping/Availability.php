<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;

/**
 * Result of an availability check.
 *
 * `available:false` comes with an explanation that distinguishes a registered name from a check that
 * could not be completed (registry unreachable, unsupported TLD). The latter is classified as
 * {@see AvailabilityState::Unknown} and raised as an error: reporting it as "registered" would lose
 * sales, and reporting it as "available" would take payment for an order that then fails.
 */
final class Availability
{
    /** Phrases OSIR uses when the check failed rather than found the name taken (pinned by tests). */
    private const array FAILURE_MARKERS = ['error checking availability', 'unexpected error', 'extension not supported', 'not supported', 'invalid domain', 'failed'];
    /** Phrases OSIR uses for a registered name when the registry gives no reason. */
    private const array REGISTERED_MARKERS = ['registered', 'in use', 'not available'];

    private function __construct(
        public readonly AvailabilityState $state,
        public readonly bool $premium,
        /** OSIR's price for one year including fees, in USD cents; null when it did not quote one. */
        public readonly ?int $totalPriceCents = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromResponse(array $data, string $requestId): self
    {
        if (!is_bool($data['available'] ?? null)) {
            throw new ApiException(ApiErrorKind::Protocol, 200, null, 'Availability response lacks "available".', $requestId);
        }
        $premium = ($data['premium'] ?? false) === true;
        $total = $data['totalPrice'] ?? null;
        $totalCents = is_int($total) && $total > 0 ? $total : null;
        if ($data['available'] === true) {
            return new self(AvailabilityState::Available, $premium, $totalCents);
        }

        $message = strtolower(is_string($data['message'] ?? null) ? $data['message'] : '');
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        foreach (self::FAILURE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return new self(AvailabilityState::Unknown, $premium, $totalCents);
            }
        }
        if ($reason !== '') {
            return new self(AvailabilityState::Registered, $premium, $totalCents);
        }
        foreach (self::REGISTERED_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return new self(AvailabilityState::Registered, $premium, $totalCents);
            }
        }

        return new self(AvailabilityState::Unknown, $premium, $totalCents);
    }
}
