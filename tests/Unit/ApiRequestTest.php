<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Http\ApiRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiRequestTest extends TestCase
{
    public function testBuildsPathFromTemplateAndDomain(): void
    {
        self::assertSame('/v2/domains/xn--mnchen-3ya.de/info', ApiRequest::path('/v2/domains/{domain}/info', DomainName::fromString('münchen.de')));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'relative' => ['v2/domains'];
        yield 'dot-dot' => ['/v2/../admin'];
        yield 'dot' => ['/v2/./x'];
        yield 'query' => ['/v2/x?y=1'];
        yield 'fragment' => ['/v2/x#y'];
        yield 'double slash' => ['//attacker.example/x'];
        yield 'space' => ['/v2/x y'];
        yield 'absolute url' => ['https://attacker.example/'];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafePaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiRequest::get($path);
    }

    public function testRejectsBadIdempotencyKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiRequest::post('/x', [], "bad\nkey");
    }

    public function testRetrySafety(): void
    {
        self::assertTrue(ApiRequest::get('/x')->isRetrySafe());
        self::assertTrue(ApiRequest::post('/x', [], 'k')->isRetrySafe());
        self::assertFalse(ApiRequest::post('/x', [])->isRetrySafe());
        self::assertFalse(ApiRequest::put('/x', [])->isRetrySafe());
    }
}
