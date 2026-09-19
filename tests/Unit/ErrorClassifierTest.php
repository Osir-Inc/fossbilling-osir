<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Http\ErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorClassifierTest extends TestCase
{
    /** @return iterable<string, array{int, ?array<array-key, mixed>, ApiErrorKind, ?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'empty 401 = bad key' => [401, null, ApiErrorKind::Authentication, null, null];
        yield '401 invalid auth code is NOT a bad key' => [401, ['success' => false, 'error' => 'Invalid auth code', 'errorCode' => 'INVALID_AUTH_CODE'], ApiErrorKind::InvalidAuthCode, 'INVALID_AUTH_CODE', 'Invalid auth code'];
        yield 'ownership helper shape' => [403, ['error' => 'Access denied: You are not the owner of this domain', 'status' => 403], ApiErrorKind::Permission, null, 'Access denied: You are not the owner of this domain'];
        yield 'account not verified' => [403, ['success' => false, 'error' => 'verify', 'errorCode' => 'ACCOUNT_NOT_VERIFIED'], ApiErrorKind::AccountNotVerified, 'ACCOUNT_NOT_VERIFIED', 'verify'];
        yield 'insufficient funds' => [402, ['success' => false, 'error' => 'Insufficient funds', 'errorCode' => 'INSUFFICIENT_FUNDS'], ApiErrorKind::InsufficientFunds, 'INSUFFICIENT_FUNDS', 'Insufficient funds'];
        yield 'spend limit' => [402, ['success' => false, 'error' => 'cap', 'errorCode' => 'SPEND_LIMIT_EXCEEDED'], ApiErrorKind::SpendLimit, 'SPEND_LIMIT_EXCEEDED', 'cap'];
        yield 'not found' => [404, ['success' => false, 'error' => 'Domain not found', 'errorCode' => 'DOMAIN_NOT_FOUND'], ApiErrorKind::NotFound, 'DOMAIN_NOT_FOUND', 'Domain not found'];
        yield 'in progress (idempotency wrapper shape)' => [409, ['success' => false, 'error' => 'busy', 'errorCode' => 'REQUEST_IN_PROGRESS', 'code' => 'REQUEST_IN_PROGRESS', 'message' => 'busy'], ApiErrorKind::InProgress, 'REQUEST_IN_PROGRESS', 'busy'];
        yield 'other 409' => [409, ['success' => false, 'error' => 'Domain is not available', 'errorCode' => 'REGISTRATION_FAILED'], ApiErrorKind::Conflict, 'REGISTRATION_FAILED', 'Domain is not available'];
        yield 'rate limiter shape' => [429, ['error' => 'Rate limit exceeded', 'retryAfterSeconds' => 3], ApiErrorKind::RateLimited, null, 'Rate limit exceeded'];
        yield 'payment shape' => [400, ['success' => false, 'data' => null, 'error' => ['message' => 'Bad amount', 'code' => 'BAD_AMOUNT']], ApiErrorKind::Rejected, 'BAD_AMOUNT', 'Bad amount'];
        yield 'bean validation shape' => [400, ['title' => 'Constraint Violation', 'status' => 400, 'violations' => [['field' => 'registerDomain.request.domain', 'message' => 'size must be between 3 and 63']]], ApiErrorKind::Rejected, null, 'domain: size must be between 3 and 63'];
        yield '500' => [500, ['success' => false, 'error' => 'Internal server error', 'errorCode' => 'INTERNAL_ERROR'], ApiErrorKind::Server, 'INTERNAL_ERROR', 'Internal server error'];
        yield 'non-error status' => [304, null, ApiErrorKind::Protocol, null, null];
        yield 'code wins over status: funds in a 400' => [400, ['success' => false, 'error' => 'no money', 'errorCode' => 'INSUFFICIENT_FUNDS'], ApiErrorKind::InsufficientFunds, 'INSUFFICIENT_FUNDS', 'no money'];
        yield 'garbage code ignored' => [400, ['errorCode' => "evil\ncode", 'error' => "line1\r\nline2"], ApiErrorKind::Rejected, null, 'line1 line2'];
    }

    /** @param array<array-key, mixed>|null $body */
    #[DataProvider('cases')]
    public function testClassifies(int $status, ?array $body, ApiErrorKind $kind, ?string $code, ?string $message): void
    {
        self::assertSame(['kind' => $kind, 'code' => $code, 'message' => $message], ErrorClassifier::classify($status, $body));
    }
}
