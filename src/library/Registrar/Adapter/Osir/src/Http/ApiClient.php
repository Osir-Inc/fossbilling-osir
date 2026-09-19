<?php

declare(strict_types=1);

namespace Osir\FossBilling\Http;

use Osir\FossBilling\Config\Settings;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\TransportException;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Version;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The single HTTP gateway to OSIR.
 *
 * Security properties enforced here, for every request:
 *   - TLS peer and host verification always on (no setting can disable it), TLS >= 1.2;
 *   - redirects are never followed, so the API key is never re-sent to another host;
 *   - the key travels only in the `X-API-Key` header and is never logged;
 *   - responses larger than {@see self::MAX_RESPONSE_BYTES} are aborted;
 *   - bodies are never logged, only method, path, status, duration and a correlation id.
 */
final class ApiClient
{
    public const int MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const string SIZE_LIMIT_MESSAGE = 'Response exceeds the size limit.';

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(float): void)|null $sleep injectable for tests
     * @param (\Closure(): float)|null     $clock monotonic seconds; injectable for tests
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Settings $settings,
        private readonly SafeLogger $log,
        private readonly RetryPolicy $retryPolicy,
        ?\Closure $sleep = null,
        ?\Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * @throws ApiException       OSIR answered with an error
     * @throws TransportException no usable answer (outcome unknown for writes)
     */
    public function send(ApiRequest $request): ApiResponse
    {
        $requestId = self::newRequestId();
        $started = ($this->clock)();

        for ($attempt = 1; ; ++$attempt) {
            $remaining = $this->retryPolicy->deadlineSeconds() - (($this->clock)() - $started);
            try {
                return $this->attempt($request, $requestId, $attempt, max(1.0, min($request->timeoutSeconds, $remaining)));
            } catch (ApiException $e) {
                $delay = $this->retryPolicy->delayAfterError($request, $attempt, ($this->clock)() - $started, $e->getKind(), $e->getHttpStatus(), $e->getRetryAfterSeconds());
                if ($delay === null) {
                    throw $e;
                }
            } catch (TransportException $e) {
                $delay = $this->retryPolicy->delayAfterError($request, $attempt, ($this->clock)() - $started, null, null, null);
                if ($delay === null) {
                    throw $e;
                }
            }

            $this->log->debug(sprintf('Retrying %s %s in %.1fs (attempt %d, ref %s)', $request->method, $request->path, $delay, $attempt + 1, $requestId));
            ($this->sleep)($delay);
        }
    }

    private function attempt(ApiRequest $request, string $requestId, int $attempt, float $timeout): ApiResponse
    {
        $started = microtime(true);

        try {
            $response = $this->http->request($request->method, $this->settings->baseUrl . $request->path, $this->options($request, $requestId, $timeout));
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (HttpClientException $e) {
            if (str_contains($e->getMessage(), self::SIZE_LIMIT_MESSAGE)) {
                // Deterministic, not a network fault: do not retry.
                throw $this->error($request, $requestId, ApiErrorKind::Protocol, 0, null, 'Response exceeds the size limit.', null, false);
            }
            $this->log->warning(sprintf('%s %s failed at transport level (attempt %d, ref %s)', $request->method, $request->path, $attempt, $requestId), ['reason' => $e->getMessage()]);

            [$reason, $maybeProcessed] = self::transportFailure($e);

            throw new TransportException($reason, $requestId, $maybeProcessed && $request->method !== 'GET', $e);
        }

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $this->log->debug(sprintf('%s %s -> %d in %d ms (attempt %d, ref %s)', $request->method, $request->path, $status, $elapsedMs, $attempt, $requestId));

        $body = self::decodeJson($content);
        $replayed = strtolower(self::header($headers, 'idempotent-replay') ?? '') === 'true';

        if ($status >= 300 && $status < 400) {
            throw $this->error($request, $requestId, ApiErrorKind::Protocol, $status, null, 'Unexpected redirect; redirects are never followed.', null, false);
        }

        if ($status >= 200 && $status < 300) {
            if ($body === null) {
                throw $this->error($request, $requestId, ApiErrorKind::Protocol, $status, null, 'Response is not a JSON object.', null, $replayed);
            }
            if (($body['success'] ?? null) === false) {
                // Some endpoints report failure inside a 2xx envelope; the error code still decides the kind.
                $classified = ErrorClassifier::classify(400, $body);

                throw $this->error($request, $requestId, $classified['kind'], $status, $classified['code'], $classified['message'], null, $replayed);
            }

            if (!$request->enveloped) {
                return new ApiResponse($status, $body, $replayed, $requestId);
            }
            if (($body['success'] ?? null) !== true) {
                throw $this->error($request, $requestId, ApiErrorKind::Protocol, $status, null, 'Expected a {success,data} envelope.', null, $replayed);
            }
            $data = $body['data'] ?? [];

            return new ApiResponse($status, is_array($data) ? $data : ['value' => $data], $replayed, $requestId);
        }

        $classified = ErrorClassifier::classify($status, $body);

        throw $this->error($request, $requestId, $classified['kind'], $status, $classified['code'], $classified['message'], self::retryAfter($headers, $body), $replayed);
    }

    /** @return array<string, mixed> */
    private function options(ApiRequest $request, string $requestId, float $timeout): array
    {
        $headers = [
            'Accept' => 'application/json',
            // Uncompressed on purpose: the size cap then counts the bytes that are actually decoded
            // (a compressed response could expand far beyond it). OSIR responses are small.
            'Accept-Encoding' => 'identity',
            'X-API-Key' => $this->settings->apiKey->reveal(),
            'X-Request-Id' => $requestId,
            'User-Agent' => sprintf('OSIR-FOSSBilling/%s (PHP %s)', Version::PLUGIN, PHP_VERSION),
        ];
        if ($request->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $request->idempotencyKey;
        }

        $options = [
            'headers' => $headers,
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            // Idle timeout. A keyed money POST may legitimately stay silent while the registry works,
            // and cutting it short only produces REQUEST_IN_PROGRESS retries; everything else gets 30 s.
            'timeout' => $request->idempotencyKey !== null ? $timeout : min(30.0, $timeout),
            'max_duration' => $timeout,
            'on_progress' => static function (int $downloaded, int $totalSize): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES || $totalSize > self::MAX_RESPONSE_BYTES) {
                    throw new \RuntimeException(self::SIZE_LIMIT_MESSAGE);
                }
            },
        ];
        if ($this->settings->caFile !== null) {
            $options['cafile'] = $this->settings->caFile;
        }
        if ($request->query !== []) {
            $options['query'] = $request->query;
        }
        $json = $request->jsonBody();
        if ($json !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $json;
        }

        return $options;
    }

    private function error(ApiRequest $request, string $requestId, ApiErrorKind $kind, int $status, ?string $code, ?string $message, ?int $retryAfter, bool $replayed): ApiException
    {
        $this->log->warning(
            sprintf('%s %s failed: %s (HTTP %d%s%s, ref %s)', $request->method, $request->path, $kind->value, $status, $code !== null ? ', ' . $code : '', $replayed ? ', replayed' : '', $requestId),
            ['upstream_message' => $message],
        );

        return new ApiException($kind, $status, $code, $message, $requestId, $retryAfter, $replayed);
    }

    /** @return array<array-key, mixed>|null */
    private static function decodeJson(string $content): ?array
    {
        if (trim($content) === '') {
            return null;
        }
        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, list<string>>  $headers
     * @param array<array-key, mixed>|null $body
     */
    private static function retryAfter(array $headers, ?array $body): ?int
    {
        $value = self::header($headers, 'retry-after') ?? (isset($body['retryAfterSeconds']) && is_int($body['retryAfterSeconds']) ? (string) $body['retryAfterSeconds'] : null);
        if ($value === null || !ctype_digit($value)) {
            return null; // HTTP-date form is not used by OSIR; ignore rather than guess
        }

        return min((int) $value, 3600);
    }

    /** @param array<string, list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $values = $headers[$name] ?? null;

        return is_array($values) && isset($values[0]) ? trim($values[0]) : null;
    }

    /**
     * Classifies a transport failure and decides whether OSIR may have processed the request.
     * DNS, TLS-handshake and connect failures happen before the request is sent, so the outcome is
     * known (nothing happened). Timeouts and resets after connecting leave writes in an unknown state.
     *
     * @return array{0: string, 1: bool} reason, and whether the request may have been processed
     */
    private static function transportFailure(\Throwable $e): array
    {
        $message = strtolower($e->getMessage());

        // Order matters: "Connection reset by peer" and "SSL_read … reset" happen AFTER sending, so the
        // after-sending patterns are checked before the words that also appear in pre-send failures.
        return match (true) {
            str_contains($message, 'reset') || str_contains($message, 'empty reply') || str_contains($message, 'recv failure')
                || str_contains($message, 'ssl_read') || str_contains($message, 'broken pipe') => ['connection lost', true],
            str_contains($message, 'idle timeout') || str_contains($message, 'max duration')
                || (str_contains($message, 'timed out') && !str_contains($message, 'connect')) => ['timeout', true],
            str_contains($message, 'resolve') || str_contains($message, 'dns') => ['DNS error', false],
            str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate') => ['TLS error', false],
            str_contains($message, 'connect') || str_contains($message, 'refused') || str_contains($message, 'unreachable') => ['connection failed', false],
            default => ['network error', true],
        };
    }

    private static function newRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
