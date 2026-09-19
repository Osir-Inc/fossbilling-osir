<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Domain\Nameservers;
use Osir\FossBilling\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class NameserversTest extends TestCase
{
    public function testIgnoresBlanksDeduplicatesAndNormalises(): void
    {
        $ns = Nameservers::fromList(['NS1.Example.NET.', null, '', 'ns1.example.net', 'ns2.example.net', '  ']);
        self::assertSame(['ns1.example.net', 'ns2.example.net'], $ns->toArray());
    }

    public function testConvertsIdnHosts(): void
    {
        self::assertSame(['ns1.xn--mnchen-3ya.de', 'ns2.example.net'], Nameservers::fromList(['ns1.münchen.de', 'ns2.example.net'])->toArray());
    }

    public function testRequiresTwoDistinctHosts(): void
    {
        $this->expectException(ValidationException::class);
        Nameservers::fromList(['ns1.example.net', 'NS1.example.net']);
    }

    public function testRejectsMoreThanThirteen(): void
    {
        $this->expectException(ValidationException::class);
        Nameservers::fromList(array_map(static fn(int $i): string => "ns{$i}.example.net", range(1, 14)));
    }

    public function testRejectsInvalidHost(): void
    {
        $this->expectException(ValidationException::class);
        Nameservers::fromList(['ns1.example.net', '10.0.0.1/../x']);
    }

    public function testRejectsNonStringValues(): void
    {
        $this->expectException(ValidationException::class);
        Nameservers::fromList(['ns1.example.net', ['ns2.example.net']]);
    }
}
