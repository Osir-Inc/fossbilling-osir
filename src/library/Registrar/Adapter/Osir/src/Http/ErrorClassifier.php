<?php

declare(strict_types=1);

namespace Osir\FossBilling\Http;

use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Support\Text;

/**
 * Normalises OSIR's error responses.
 *
 * Error bodies come in these shapes:
 *   - envelope            {"success":false,"error":"…","errorCode":"…","resolution":"…"}
 *   - ownership helper    {"error":"…","status":404}
 *   - idempotency wrapper {"success":false,"error":"…","errorCode":"…","code":"…","message":"…"}
 *   - rate limiter        {"error":"Rate limit exceeded","retryAfterSeconds":N}
 *   - payment endpoints   {"success":false,"error":{"message":"…","code":"…"}}
 *   - bean validation     {"title":"…","violations":[{"field":"…","message":"…"}]}
 *   - security layer      empty body (401/403)
 * A status code can stand for more than one condition (401: bad API key or bad transfer auth code;
 * 409: domain unavailable or request in progress), so a known error code wins over the status.
 */
final class ErrorClassifier
{
    /**
     * @param array<array-key, mixed>|null $body decoded JSON body, or null when absent / not JSON
     *
     * @return array{kind: ApiErrorKind, code: string|null, message: string|null}
     */
    public static function classify(int $status, ?array $body): array
    {
        $code = self::extractCode($body);
        $message = self::extractMessage($body);

        $kind = self::kindForCode($code) ?? match (true) {
            $status === 401 => ApiErrorKind::Authentication,
            $status === 402 => ApiErrorKind::InsufficientFunds,
            $status === 403 => ApiErrorKind::Permission,
            $status === 404 => ApiErrorKind::NotFound,
            $status === 409 => ApiErrorKind::Conflict,
            $status === 429 => ApiErrorKind::RateLimited,
            $status >= 500 && $status <= 599 => ApiErrorKind::Server,
            $status >= 400 && $status <= 499 => ApiErrorKind::Rejected,
            default => ApiErrorKind::Protocol,
        };

        return ['kind' => $kind, 'code' => $code, 'message' => $message];
    }

    /**
     * Error codes that identify the condition regardless of the HTTP status they arrive with
     * (OSIR reuses 401 for a bad transfer code, and some failures arrive inside a 2xx envelope).
     */
    private static function kindForCode(?string $code): ?ApiErrorKind
    {
        return match ($code) {
            'INVALID_AUTH_CODE' => ApiErrorKind::InvalidAuthCode,
            'INSUFFICIENT_FUNDS' => ApiErrorKind::InsufficientFunds,
            'SPEND_LIMIT_EXCEEDED' => ApiErrorKind::SpendLimit,
            'ACCOUNT_NOT_VERIFIED' => ApiErrorKind::AccountNotVerified,
            'REQUEST_IN_PROGRESS' => ApiErrorKind::InProgress,
            default => null,
        };
    }

    /** @param array<array-key, mixed>|null $body */
    private static function extractCode(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        foreach ([$body['errorCode'] ?? null, $body['code'] ?? null, is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null] as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed>|null $body */
    private static function extractMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $candidates = [
            $body['error'] ?? null,
            $body['message'] ?? null,
            is_array($body['error'] ?? null) ? ($body['error']['message'] ?? null) : null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return Text::sanitize($candidate, 300);
            }
        }

        if (isset($body['violations']) && is_array($body['violations'])) {
            $parts = [];
            foreach (array_slice($body['violations'], 0, 5) as $violation) {
                if (is_array($violation) && is_string($violation['message'] ?? null)) {
                    $field = is_string($violation['field'] ?? null) ? preg_replace('/^.*\./', '', $violation['field']) . ': ' : '';
                    $parts[] = $field . $violation['message'];
                }
            }
            if ($parts !== []) {
                return Text::sanitize(implode('; ', $parts), 300);
            }
        }

        return is_string($body['title'] ?? null) ? Text::sanitize($body['title'], 300) : null;
    }
}
