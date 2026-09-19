<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Support\Redactor;
use Osir\FossBilling\Support\SafeLogger;
use Osir\FossBilling\Support\Text;
use Osir\FossBilling\Tests\Support\CapturingLogger;
use Osir\FossBilling\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testRedactsSensitiveKeysAtAnyDepthAndKeysInText(): void
    {
        $out = Redactor::redactArray([
            'domain' => 'example.com',
            'X-API-Key' => Fixtures::LIVE_KEY,
            'nested' => ['authCode' => 'abc', 'registrant' => ['email' => 'a@b.c'], 'note' => 'uses ' . Fixtures::TEST_KEY],
        ]);
        self::assertSame('example.com', $out['domain']);
        self::assertSame(Redactor::MASK, $out['X-API-Key']);
        self::assertIsArray($out['nested']);
        self::assertSame(Redactor::MASK, $out['nested']['authCode']);
        self::assertSame(Redactor::MASK, $out['nested']['registrant']);
        self::assertSame('uses osir_***', $out['nested']['note']);
    }

    public function testTextSanitiserPreventsLogForging(): void
    {
        // A forged second log line collapses into the first: one entry, no newline.
        self::assertSame('ok [ERROR] FAKE ENTRY', Text::sanitize("ok\n\r[ERROR]\x07FAKE ENTRY", 100));
        self::assertStringNotContainsString("\n", Text::sanitize("a\nb"));
        self::assertSame('ab…', Text::sanitize('abcdef', 2));
        self::assertSame('Bearer ***', Text::sanitize('Bearer eyJhbGciOi.abc.def'));
        // Unicode line separators and bidi overrides can fake lines / reorder text in log viewers.
        self::assertSame('a b c d', Text::sanitize("a\u{2028}b\u{202E}c\u{2066}d"));
        // Contact data echoed by upstream errors is masked.
        self::assertSame('owner [email] phone [phone]', Text::sanitize('owner ada@example.org phone +44.2079460000'));
        self::assertSame('call [phone] now', Text::sanitize('call +442079460000 now'));
    }

    public function testSafeLoggerPassesExactlyOneStringAndDropsDebugWhenDisabled(): void
    {
        $logger = new CapturingLogger();
        $safe = new SafeLogger($logger, false);
        $safe->info('100% done', ['apiKey' => Fixtures::LIVE_KEY]);
        $safe->debug('hidden');

        self::assertCount(1, $logger->lines);
        self::assertStringStartsWith('[OSIR] 100% done ', $logger->lines[0]['message']);
        self::assertStringNotContainsString('AbCdEf', $logger->all());
    }

    public function testSafeLoggerSurvivesBrokenOrMissingLoggers(): void
    {
        (new SafeLogger(null))->error('x');
        (new SafeLogger(new \stdClass()))->error('x');
        (new SafeLogger(new class {
            public function error(string $m): void
            {
                throw new \RuntimeException('disk full');
            }
        }))->error('x');
        $this->addToAssertionCount(1);
    }

    public function testWorksWithFossBillingsRealBoxLog(): void
    {
        // A real Box_LogDb subclass that captures instead of writing to the database.
        $writer = new class ('capture') extends \Box_LogDb {
            /** @var list<array<array-key, mixed>> */
            public array $events = [];

            /** @param array<array-key, mixed> $event */
            public function write(array $event, string $channel = 'application'): void
            {
                $this->events[] = $event;
            }
        };
        $boxLog = new \Box_Log();
        $boxLog->addWriter($writer);

        (new SafeLogger($boxLog, true))->warning('50% of %s done %d', ['a' => 1]);

        self::assertCount(1, $writer->events);
        self::assertIsString($writer->events[0]['message']);
        self::assertStringContainsString('50% of %s done %d', $writer->events[0]['message']);
    }
}
