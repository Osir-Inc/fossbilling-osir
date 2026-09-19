<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A MockHttpClient that answers from a FIFO script and records every request, so tests can
 * assert on exactly what would have been sent to OSIR (method, URL, headers, JSON body, options).
 */
final class ScriptedHttpClient
{
    /** @var list<MockResponse|\Closure(RecordedRequest): MockResponse> */
    private array $script = [];

    /** @var list<RecordedRequest> */
    public array $requests = [];

    public readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, mixed> $options */
            $recorded = RecordedRequest::fromOptions($method, $url, $options);
            $this->requests[] = $recorded;
            $next = array_shift($this->script);
            if ($next === null) {
                throw new \LogicException(sprintf('Unexpected request %s %s (script exhausted)', $method, $url));
            }

            return $next instanceof \Closure ? $next($recorded) : $next;
        });
    }

    /**
     * @param array<array-key, mixed>|null $json
     * @param array<string, string>        $headers
     */
    public function json(int $status, ?array $json, array $headers = []): self
    {
        $this->script[] = new MockResponse(
            $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => array_merge(['content-type' => 'application/json'], $headers)],
        );

        return $this;
    }

    /**
     * OSIR's standard success envelope.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string>   $headers
     */
    public function envelope(int $status, array $data, array $headers = []): self
    {
        return $this->json($status, ['success' => true, 'data' => $data, 'timestamp' => '2026-09-19T10:00:00Z'], $headers);
    }

    /** @param array<string, string> $headers */
    public function raw(int $status, string $body, array $headers = []): self
    {
        $this->script[] = new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);

        return $this;
    }

    public function transportError(string $message = 'Idle timeout reached for "https://be.osir.com".'): self
    {
        $this->script[] = new MockResponse('', ['error' => $message]);

        return $this;
    }

    /** @param \Closure(RecordedRequest): MockResponse $responder */
    public function respond(\Closure $responder): self
    {
        $this->script[] = $responder;

        return $this;
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    public function last(): RecordedRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }
}
