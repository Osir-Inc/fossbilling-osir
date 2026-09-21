<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\TransportException;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\ApiRequest;
use Osir\FossBilling\Http\RetryPolicy;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use Osir\FossBilling\Tests\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiClientTest extends TestCase
{
    public function testSendsAuthAndSecurityOptionsOnEveryRequest(): void
    {
        $http = (new ScriptedHttpClient())->envelope(200, ['ok' => true]);
        Fixtures::apiClient($http)->send(ApiRequest::get('/v2/domains/example.com/info', ['environment' => 'prod']));

        $r = $http->last();
        self::assertSame('GET', $r->method);
        self::assertSame('https://be.osir.com/v2/domains/example.com/info?environment=prod', $r->url);
        self::assertSame(Fixtures::LIVE_KEY, $r->headers['x-api-key']);
        self::assertSame('application/json', $r->headers['accept']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $r->headers['x-request-id']);
        self::assertStringStartsWith('OSIR-FOSSBilling/', $r->headers['user-agent']);
        self::assertArrayNotHasKey('authorization', $r->headers);
        self::assertSame(0, $r->options['max_redirects']);
        self::assertTrue($r->options['verify_peer']);
        self::assertTrue($r->options['verify_host']);
        self::assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $r->options['crypto_method']);
    }

    public function testUnwrapsEnvelopeAndPassesThroughUnwrappedBodies(): void
    {
        $http = (new ScriptedHttpClient())
            ->envelope(200, ['domain' => 'example.com'])
            ->json(200, ['available' => true, 'domain' => 'example.com']);
        $client = Fixtures::apiClient($http);

        self::assertSame(['domain' => 'example.com'], $client->send(ApiRequest::get('/a'))->data);
        self::assertSame(['available' => true, 'domain' => 'example.com'], $client->send(ApiRequest::get('/b')->unwrapped())->data);
    }

    public function testEnvelopedEndpointMustReturnAnEnvelope(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['domain' => 'example.com']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/a'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::Protocol, $e->getKind());
        }
    }

    public function testUnwrappedDtoWithItsOwnSuccessFieldKeepsItsPayload(): void
    {
        // e.g. a transfer result: a bare object that happens to contain "success": true.
        $http = (new ScriptedHttpClient())->json(200, ['success' => true, 'domain' => 'example.com', 'status' => 'PENDING']);
        self::assertSame('PENDING', Fixtures::apiClient($http)->send(ApiRequest::get('/t')->unwrapped())->data['status']);
    }

    public function testErrorCodeInsideA2xxDecidesTheKind(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['success' => false, 'error' => 'no money', 'errorCode' => 'INSUFFICIENT_FUNDS']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::InsufficientFunds, $e->getKind());
        }
    }

    public function testReplayedErrorIsFlagged(): void
    {
        $http = (new ScriptedHttpClient())->json(500, ['success' => false, 'error' => 'stored'], ['idempotent-replay' => 'true']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::post('/x', [], 'k'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertTrue($e->isReplayed());
            self::assertSame(ApiErrorKind::Server, $e->getKind());
        }
        self::assertCount(1, $http->requests, 'a stored 500 is not retried under the same key');
    }

    public function testDeadlineCoversTimeSpentInsideAttempts(): void
    {
        // One shared fake clock that advances both while an attempt "runs" and while sleeping.
        $now = 0.0;
        $slowFailure = static function () use (&$now): MockResponse {
            $now += 45.0;

            return new MockResponse('', ['http_code' => 503]);
        };
        $http = (new ScriptedHttpClient())->respond($slowFailure)->respond($slowFailure);
        $client = new ApiClient(
            $http->client,
            Fixtures::settings(),
            new SafeLogger(new CapturingLogger()),
            new RetryPolicy(5, 90.0, 0.5),
            static function (float $s) use (&$now): void {
                $now += $s;
            },
            static function () use (&$now): float {
                return $now;
            },
        );

        try {
            $client->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->getHttpStatus());
        }
        self::assertCount(2, $http->requests, 'no attempt starts once the 90 s deadline is used up');
        $secondMax = $http->requests[1]->options['max_duration'];
        self::assertIsFloat($secondMax);
        self::assertLessThan(45.0, $secondMax, 'a later attempt only gets the time that is left');
    }

    public function testSendsJsonBodyAndIdempotencyKey(): void
    {
        $http = (new ScriptedHttpClient())->json(201, ['success' => true, 'data' => ['status' => 'COMPLETED']], ['idempotent-replay' => 'true']);
        $response = Fixtures::apiClient($http)->send(ApiRequest::post('/v2/domains/register', ['domain' => 'example.com', 'period' => 1], 'fb:k:1'));

        $r = $http->last();
        self::assertSame(['domain' => 'example.com', 'period' => 1], $r->json);
        self::assertSame('application/json', $r->headers['content-type']);
        self::assertSame('fb:k:1', $r->headers['idempotency-key']);
        self::assertTrue($response->replayed);
    }

    public function testSuccessFalseInsideA2xxIsAnError(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['success' => false, 'error' => 'Registry said no', 'errorCode' => 'REGISTRATION_FAILED']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::Rejected, $e->getKind());
            self::assertSame('REGISTRATION_FAILED', $e->getErrorCode());
            self::assertSame('Registry said no', $e->getUpstreamMessage());
        }
    }

    public function testNeverFollowsRedirects(): void
    {
        $http = (new ScriptedHttpClient())->raw(302, '', ['location' => 'https://attacker.example/steal']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::Protocol, $e->getKind());
        }
        self::assertCount(1, $http->requests);
    }

    public function testNonJsonSuccessIsAProtocolError(): void
    {
        $http = (new ScriptedHttpClient())->raw(200, '<html>maintenance</html>');
        $this->expectException(ApiException::class);
        Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
    }

    public function testRetriesSafeGetOn503ThenSucceeds(): void
    {
        $sleeps = [];
        $http = (new ScriptedHttpClient())->json(503, null)->envelope(200, ['ok' => true]);
        $response = Fixtures::apiClient($http, sleeps: $sleeps)->send(ApiRequest::get('/x'));

        self::assertSame(['ok' => true], $response->data);
        self::assertCount(2, $http->requests);
        self::assertCount(1, $sleeps);
        self::assertSame($http->requests[0]->headers['x-request-id'], $http->requests[1]->headers['x-request-id'], 'one correlation id per logical call');
    }

    public function testDoesNotRetryPostWithoutIdempotencyKeyOn503(): void
    {
        $http = (new ScriptedHttpClient())->json(503, null);
        $this->expectException(ApiException::class);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::post('/x', []));
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetriesPostWithIdempotencyKeyOnTransportErrorAndInProgress(): void
    {
        $sleeps = [];
        $http = (new ScriptedHttpClient())
            ->transportError()
            ->json(409, ['success' => false, 'errorCode' => 'REQUEST_IN_PROGRESS', 'error' => 'wait'])
            ->envelope(201, ['status' => 'COMPLETED']);
        Fixtures::apiClient($http, sleeps: $sleeps)->send(ApiRequest::post('/x', [], 'key-1'));

        self::assertCount(3, $http->requests);
        foreach ($http->requests as $r) {
            self::assertSame('key-1', $r->headers['idempotency-key'], 'retries must reuse the SAME key');
        }
    }

    public function testRetries429EvenForUnkeyedWritesHonouringRetryAfter(): void
    {
        $sleeps = [];
        $http = (new ScriptedHttpClient())
            ->json(429, ['error' => 'Rate limit exceeded', 'retryAfterSeconds' => 2], ['retry-after' => '2'])
            ->envelope(200, []);
        Fixtures::apiClient($http, sleeps: $sleeps)->send(ApiRequest::put('/x', []));

        self::assertSame([2.0], $sleeps);
    }

    public function testGivesUpWhenRetryAfterExceedsBudget(): void
    {
        $http = (new ScriptedHttpClient())->json(429, ['error' => 'Rate limit exceeded'], ['retry-after' => '120']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::RateLimited, $e->getKind());
            self::assertSame(120, $e->getRetryAfterSeconds());
        }
        self::assertCount(1, $http->requests);
    }

    public function testStopsAfterMaxAttempts(): void
    {
        $http = (new ScriptedHttpClient())->json(503, null)->json(503, null)->json(503, null);
        $this->expectException(ApiException::class);
        try {
            Fixtures::apiClient($http, retry: new RetryPolicy(3, 60.0, 0.1))->send(ApiRequest::get('/x'));
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testTimeoutOnWriteIsFlaggedOutcomeUnknown(): void
    {
        $http = (new ScriptedHttpClient())->transportError('Idle timeout reached for "https://be.osir.com/x".');
        try {
            Fixtures::apiClient($http)->send(ApiRequest::put('/x', []));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isOutcomeUnknown());
            self::assertStringContainsString('timeout', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function failuresBeforeSending(): iterable
    {
        yield 'TLS' => ['SSL certificate problem: unable to get local issuer certificate', 'TLS error'];
        yield 'DNS' => ['Could not resolve host: be.osir.com', 'DNS error'];
        yield 'connect' => ['Failed to connect to be.osir.com port 443: Connection refused', 'connection failed'];
        yield 'connect timeout' => ['Connection timed out after 10001 milliseconds while connecting', 'connection failed'];
    }

    /** @return iterable<string, array{string}> */
    public static function failuresAfterSending(): iterable
    {
        yield 'reset' => ['Recv failure: Connection reset by peer'];
        yield 'TLS read reset' => ['OpenSSL SSL_read: Connection reset by peer, errno 104'];
        yield 'empty reply' => ['Empty reply from server'];
        yield 'idle' => ['Idle timeout reached for "https://be.osir.com/x".'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failuresAfterSending')]
    public function testFailuresAfterSendingLeaveTheOutcomeUnknown(string $message): void
    {
        $http = (new ScriptedHttpClient())->transportError($message);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::put('/x', []));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isOutcomeUnknown());
        }
    }

    public function testKeyedMoneyPostsMayStaySilentForTheWholeAttempt(): void
    {
        $http = (new ScriptedHttpClient())->envelope(201, [])->envelope(200, []);
        $client = Fixtures::apiClient($http);
        $client->send(ApiRequest::post('/x', [], 'k'));
        self::assertEqualsWithDelta(90.0, $http->requests[0]->options['timeout'], 0.01);
        $client->send(ApiRequest::get('/y'));
        self::assertEqualsWithDelta(30.0, $http->requests[1]->options['timeout'], 0.01);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failuresBeforeSending')]
    public function testFailuresBeforeSendingHaveAKnownOutcome(string $message, string $reason): void
    {
        $http = (new ScriptedHttpClient())->transportError($message);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::put('/x', []));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertFalse($e->isOutcomeUnknown(), 'nothing was sent, so nothing can have happened');
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function testAbortsOversizedResponses(): void
    {
        $http = (new ScriptedHttpClient())->raw(200, str_repeat('x', 2 * 1024 * 1024 + 10), ['content-type' => 'application/json']);
        try {
            Fixtures::apiClient($http)->send(ApiRequest::get('/x'));
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiErrorKind::Protocol, $e->getKind());
        }
        self::assertCount(1, $http->requests, 'a deterministic protocol violation is not retried');
    }

    public function testUsesCustomCaFileWhenConfigured(): void
    {
        $settings = new \Osir\FossBilling\Config\Settings(
            \Osir\FossBilling\Config\Environment::Live,
            new \Osir\FossBilling\Config\Secret(Fixtures::LIVE_KEY),
            'https://api.osir.test',
            '/certs/ca.pem',
            'i',
            null,
            false,
            false,
            false,
            'settings',
        );
        $http = (new ScriptedHttpClient())->envelope(200, []);
        Fixtures::apiClient($http, $settings)->send(ApiRequest::get('/x'));
        self::assertSame('/certs/ca.pem', $http->last()->options['cafile']);
        self::assertStringStartsWith('https://api.osir.test/', $http->last()->url);
    }

    public function testNeverLogsTheKeyOrBodies(): void
    {
        $logger = new CapturingLogger();
        $http = (new ScriptedHttpClient())
            ->envelope(200, ['authCode' => 'S3cr3t-Auth'])
            ->json(402, ['success' => false, 'error' => 'Insufficient funds. key osir_live_AbCdEfGhIjKlMnOpQrStUvWxYz012345']);
        $client = Fixtures::apiClient($http, logger: $logger);
        $client->send(ApiRequest::get(ApiRequest::path('/v2/domains/{domain}/authcode', DomainName::fromString('example.com'))));
        try {
            $client->send(ApiRequest::post('/v2/domains/register', ['registrant' => ['email' => 'ada@example.org']], 'k'));
        } catch (ApiException) {
        }

        $all = $logger->all();
        self::assertNotSame('', $all);
        self::assertStringNotContainsString('AbCdEfGhIjKl', $all);
        self::assertStringNotContainsString('S3cr3t-Auth', $all);
        self::assertStringNotContainsString('ada@example.org', $all);
    }
}
