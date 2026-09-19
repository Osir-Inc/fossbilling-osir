<?php

declare(strict_types=1);

namespace Osir\FossBilling\Http;

use Osir\FossBilling\Domain\DomainName;

/**
 * One call to the OSIR API. Paths are built only through {@see self::path()}, which accepts a
 * fixed template and validated {@see DomainName} arguments — never free-form strings — so request
 * targets cannot be influenced by user input.
 */
final class ApiRequest
{
    /**
     * @param array<string, scalar>        $query
     * @param array<string, mixed>|null    $body            JSON body; null sends no body
     * @param string|null                  $idempotencyKey  makes a POST safe to retry (OSIR deduplicates)
     * @param bool                         $enveloped       whether a success body is OSIR's {success,data} envelope
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly ?array $body,
        public readonly ?string $idempotencyKey,
        public readonly float $timeoutSeconds,
        public readonly bool $enveloped = true,
    ) {}

    /**
     * Marks an endpoint whose success body is the bare DTO rather than the {success,data}
     * envelope (availability, quotes, transfer endpoints). Declared per call site instead of being
     * guessed from the body, because some bare DTOs contain their own `success` field.
     */
    public function unwrapped(): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->idempotencyKey, $this->timeoutSeconds, false);
    }

    /** JSON body to send; an empty body is sent as an object, which is what OSIR's DTOs expect. */
    public function jsonBody(): ?string
    {
        if ($this->body === null) {
            return null;
        }

        return $this->body === [] ? '{}' : json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, scalar> $query */
    public static function get(string $path, array $query = []): self
    {
        return new self('GET', self::assertPath($path), $query, null, null, 45.0);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, scalar> $query
     */
    public static function post(string $path, array $body, ?string $idempotencyKey = null, array $query = [], float $timeoutSeconds = 120.0): self
    {
        if ($idempotencyKey !== null && (strlen($idempotencyKey) > 255 || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1)) {
            throw new \InvalidArgumentException('Idempotency key must be 1-255 printable ASCII characters.');
        }

        return new self('POST', self::assertPath($path), $query, $body, $idempotencyKey, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, scalar> $query
     */
    public static function put(string $path, array $body, array $query = []): self
    {
        return new self('PUT', self::assertPath($path), $query, $body, null, 120.0);
    }

    /**
     * Fills a path template: every "{domain}" placeholder is replaced by the domain's encoded
     * ASCII form. Templates are compile-time constants in the service layer.
     */
    public static function path(string $template, DomainName $domain): string
    {
        return str_replace('{domain}', $domain->pathSegment(), $template);
    }

    /** Whether repeating this request cannot cause a second side effect. */
    public function isRetrySafe(): bool
    {
        return $this->method === 'GET' || $this->idempotencyKey !== null;
    }

    private static function assertPath(string $path): string
    {
        if (
            preg_match('#^/[A-Za-z0-9._~%/-]*$#', $path) !== 1
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1
            || str_contains($path, '//')
        ) {
            throw new \InvalidArgumentException('Refusing to build an unsafe OSIR API path.');
        }
        return $path;
    }
}
