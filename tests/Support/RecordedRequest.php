<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Support;

final class RecordedRequest
{
    /**
     * @param array<string, string>      $headers lower-cased names
     * @param array<array-key, mixed>|null $json
     * @param array<string, mixed>       $options the normalised Symfony options
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?array $json,
        public readonly array $options,
    ) {}

    /** @param array<string, mixed> $options */
    public static function fromOptions(string $method, string $url, array $options): self
    {
        $headers = [];
        $normalized = $options['normalized_headers'] ?? [];
        if (is_array($normalized)) {
            foreach ($normalized as $name => $lines) {
                if (is_string($name) && is_array($lines) && isset($lines[0]) && is_string($lines[0])) {
                    $headers[strtolower($name)] = trim(explode(':', $lines[0], 2)[1] ?? '');
                }
            }
        }

        $json = null;
        $body = $options['body'] ?? '';
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            $json = is_array($decoded) ? $decoded : null;
        }

        return new self($method, $url, $headers, $json, $options);
    }

    public function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /** @return array<string, string> */
    public function query(): array
    {
        $raw = parse_url($this->url, PHP_URL_QUERY);
        parse_str(is_string($raw) ? $raw : '', $query);
        $out = [];
        foreach ($query as $k => $v) {
            $out[(string) $k] = is_string($v) ? $v : '';
        }

        return $out;
    }
}
