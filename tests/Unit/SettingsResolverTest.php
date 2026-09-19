<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Config\SettingsResolver;
use Osir\FossBilling\Exception\ConfigurationException;
use Osir\FossBilling\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsResolverTest extends TestCase
{
    /** @param array<string, string> $server */
    private static function resolver(array $server = []): SettingsResolver
    {
        return new SettingsResolver(static fn(string $name): ?string => $server[$name] ?? null);
    }

    public function testLiveModeUsesLiveKeyAndDefaults(): void
    {
        $s = self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY, 'api_key_test' => Fixtures::TEST_KEY], false, 'https://billing.example/');
        self::assertSame(Environment::Live, $s->environment);
        self::assertSame(Fixtures::LIVE_KEY, $s->apiKey->reveal());
        self::assertSame('https://be.osir.com', $s->baseUrl);
        self::assertNull($s->caFile);
        self::assertNull($s->maxYearlyCostCents);
        self::assertFalse($s->initializeDnsZone);
        self::assertFalse($s->debug);
        self::assertSame('settings', $s->source);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $s->installationId);
    }

    public function testTestModeIsRefusedWhateverKeysExist(): void
    {
        // OSIR has no sandbox: Test Mode must never quietly become live, paid operations.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('OSIR has no test environment');
        self::resolver(['OSIR_REGISTRAR_API_KEY' => Fixtures::LIVE_KEY])->resolve(['api_key' => Fixtures::LIVE_KEY, 'api_key_test' => Fixtures::TEST_KEY], true, 'x');
    }

    public function testServerLevelKeyWinsOverDatabase(): void
    {
        $other = 'osir_live_ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
        $s = self::resolver(['OSIR_REGISTRAR_API_KEY' => $other])->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'x');
        self::assertSame($other, $s->apiKey->reveal());
        self::assertSame('server', $s->source);
    }

    public function testInstallationIdIsStablePerSeed(): void
    {
        $a = self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'https://a.example/');
        $b = self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'https://a.example/');
        $c = self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'https://b.example/');
        self::assertSame($a->installationId, $b->installationId);
        self::assertNotSame($a->installationId, $c->installationId);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string}> */
    public static function badKeys(): iterable
    {
        yield 'missing key' => [[], false, 'No OSIR API key'];
        yield 'old sandbox key' => [['api_key' => Fixtures::TEST_KEY], false, 'Only live OSIR API keys'];
        yield 'test mode' => [['api_key' => Fixtures::LIVE_KEY], true, 'no test environment'];
        yield 'malformed' => [['api_key' => 'osir_live_short'], false, 'malformed'];
        yield 'header injection' => [['api_key' => Fixtures::LIVE_KEY . "\r\nX-Evil: 1"], false, 'malformed'];
        yield 'JWT pasted' => [['api_key' => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.e30.x'], false, 'malformed'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('badKeys')]
    public function testRejectsBadKeys(array $config, bool $testMode, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        self::resolver()->resolve($config, $testMode, 'x');
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'http' => ['http://api.osir.test'];
        yield 'path' => ['https://api.osir.test/v2'];
        yield 'credentials' => ['https://user:pass@api.osir.test'];
        yield 'query' => ['https://api.osir.test?x=1'];
        yield 'garbage' => ['not a url'];
    }

    #[DataProvider('badUrls')]
    public function testRejectsUnsafeBaseUrlOverrides(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        self::resolver(['OSIR_REGISTRAR_API_URL' => $url])->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'x');
    }

    public function testAcceptsHttpsOverrideAndReadableCaFile(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        self::assertIsString($ca);
        try {
            $s = self::resolver(['OSIR_REGISTRAR_API_URL' => 'https://api.osir.test:8443/', 'OSIR_REGISTRAR_CA_FILE' => $ca])
                ->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'x');
            self::assertSame('https://api.osir.test:8443', $s->baseUrl);
            self::assertSame($ca, $s->caFile);
        } finally {
            unlink($ca);
        }
    }

    public function testRejectsMissingCaFile(): void
    {
        $this->expectException(ConfigurationException::class);
        self::resolver(['OSIR_REGISTRAR_CA_FILE' => '/nonexistent/ca.pem'])->resolve(['api_key' => Fixtures::LIVE_KEY], false, 'x');
    }

    public function testParsesOptionalSettings(): void
    {
        $s = self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY, 'max_yearly_cost' => '25.50', 'initialize_dns_zone' => '1', 'debug_logging' => '1'], false, 'x');
        self::assertSame(2550, $s->maxYearlyCostCents);
        self::assertTrue($s->initializeDnsZone);
        self::assertTrue($s->debug);
    }

    /** @return iterable<string, array{string}> */
    public static function badCaps(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'rounds to zero cents' => ['0.004'];
        yield 'not a number' => ['ten'];
    }

    #[DataProvider('badCaps')]
    public function testRejectsInvalidCostCap(string $cap): void
    {
        $this->expectException(ConfigurationException::class);
        self::resolver()->resolve(['api_key' => Fixtures::LIVE_KEY, 'max_yearly_cost' => $cap], false, 'x');
    }

    public function testKeysInFossBillingsConfigArrayAreServerLevel(): void
    {
        $config = static fn(string $path): mixed => match ($path) {
            'osir.api_key' => 'osir_live_FromConfigArray0123456789',
            default => null,
        };
        $live = SettingsResolver::fromRuntime($config)->resolve(['api_key' => 'osir_live_StoredInDatabase012345678'], false, 'seed');
        self::assertSame('server', $live->source, 'the config array wins over the database, like a define()');
        self::assertStringStartsWith('osir_live_Fr', $live->apiKey->hint());

    }

    public function testMissingOrNonStringConfigArrayEntryFallsBackToTheDatabase(): void
    {
        $settings = SettingsResolver::fromRuntime(static fn(string $path): mixed => ['nested' => 'array'])
            ->resolve(['api_key' => 'osir_live_StoredInDatabase012345678'], false, 'seed');
        self::assertSame('settings', $settings->source);
    }
}
