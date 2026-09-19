<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainNameTest extends TestCase
{
    public function testNormalisesCaseWhitespaceAndRootDot(): void
    {
        self::assertSame('example.com', DomainName::fromString('  Example.COM. ')->ascii());
    }

    public function testConvertsUnicodeToPunycodeAndBack(): void
    {
        $name = DomainName::fromString('München.de');
        self::assertSame('xn--mnchen-3ya.de', $name->ascii());
        self::assertSame('münchen.de', $name->unicode());
    }

    public function testAcceptsValidPunycodeInput(): void
    {
        self::assertSame('xn--mnchen-3ya.de', DomainName::fromString('xn--mnchen-3ya.de')->ascii());
    }

    public function testFromPartsJoinsSldAndTld(): void
    {
        $name = DomainName::fromParts('example', '.co.uk');
        self::assertSame('example.co.uk', $name->ascii());
        self::assertSame('co.uk', $name->tld());
    }

    public function testFromPartsRejectsDotInSld(): void
    {
        $this->expectException(ValidationException::class);
        DomainName::fromParts('evil.example', '.com');
    }

    /** U+3002 / U+FF0E / U+FF61 become "." under UTS #46; the SLD check must run after mapping. */
    public function testFromPartsRejectsIdeographicFullStopsInSld(): void
    {
        foreach (["evil\u{3002}example", "evil\u{FF0E}example", "evil\u{FF61}example"] as $sld) {
            try {
                DomainName::fromParts($sld, '.com');
                self::fail('Expected ValidationException for ' . bin2hex($sld));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'path traversal' => ['../admin'];
        yield 'path traversal inside' => ['a/../b.com'];
        yield 'slash' => ['a/b.com'];
        yield 'query' => ['x?y=1.com'];
        yield 'fragment' => ['evil.com#frag'];
        yield 'percent' => ['ex%2fample.com'];
        yield 'space' => ['exa mple.com'];
        yield 'CRLF' => ["example.com\r\nX-Evil: 1"];
        yield 'at sign' => ['user@example.com'];
        yield 'single label' => ['localhost'];
        yield 'leading hyphen' => ['-abc.com'];
        yield 'trailing hyphen' => ['abc-.com'];
        yield 'underscore' => ['ab_c.com'];
        yield 'empty label' => ['a..com'];
        yield 'numeric tld / IPv4' => ['192.168.0.1'];
        yield 'reserved hyphens' => ['ab--cd.com'];
        yield 'bogus A-label' => ['xn--zzzzzzzzzz-.com'];
        yield 'label too long' => [str_repeat('a', 64) . '.com'];
        yield 'name too long' => [implode('.', array_fill(0, 5, str_repeat('a', 60))) . '.com'];
        yield 'disallowed code point (U+FFFD)' => ["ex\u{FFFD}ample.com"];
        yield 'line separator' => ["exam\u{2028}ple.com"];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $input): void
    {
        $this->expectException(ValidationException::class);
        DomainName::fromString($input);
    }

    /**
     * UTS #46 permits emoji and some registries (e.g. .ws) register them, so the registry — not
     * the adapter — decides. The adapter only guarantees a clean A-label.
     */
    public function testEmojiBecomesAnALabelAndRegistryDecides(): void
    {
        self::assertStringStartsWith('xn--', DomainName::fromString('😀.ws')->ascii());
    }

    public function testPathSegmentIsInert(): void
    {
        self::assertSame('xn--mnchen-3ya.de', DomainName::fromString('münchen.de')->pathSegment());
    }
}
