<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Config\Secret;
use Osir\FossBilling\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    public function testRevealsOnlyExplicitly(): void
    {
        self::assertSame(Fixtures::LIVE_KEY, (new Secret(Fixtures::LIVE_KEY))->reveal());
    }

    public function testDoesNotLeakThroughAnyDumpMechanism(): void
    {
        $secret = new Secret(Fixtures::LIVE_KEY);
        $holder = new \ArrayObject(['secret' => $secret]);

        $outputs = [
            (string) $secret,
            print_r($secret, true),
            var_export($secret, true),
            json_encode($secret, JSON_THROW_ON_ERROR),
            print_r((array) $secret, true),
            print_r($holder, true),
            $secret->hint(),
        ];
        ob_start();
        var_dump($secret);
        $outputs[] = (string) ob_get_clean();

        foreach ($outputs as $output) {
            self::assertStringNotContainsString('AbCdEfGhIjKl', $output);
        }
    }

    public function testRefusesSerialisationAndCloning(): void
    {
        $secret = new Secret(Fixtures::LIVE_KEY);
        try {
            serialize($secret);
            self::fail('serialize() must throw');
        } catch (\LogicException) {
        }

        $this->expectException(\Error::class);
        $clone = clone $secret;
        unset($clone);
    }

    public function testHintShowsPrefixAndLengthOnly(): void
    {
        self::assertSame('osir_live_Ab…(42 chars)', (new Secret(Fixtures::LIVE_KEY))->hint());
    }
}
