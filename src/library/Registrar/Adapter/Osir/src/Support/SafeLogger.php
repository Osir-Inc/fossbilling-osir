<?php

declare(strict_types=1);

namespace Osir\FossBilling\Support;

/**
 * The only way the adapter writes to FOSSBilling's log.
 *
 * FOSSBilling 0.8.x passes a Box_Log, newer versions a PSR-3 logger. Both accept
 * `<level>(string $message)` for error/warning/info/debug, and Box_Log runs vsprintf() over
 * extra arguments — so this class only ever passes ONE fully formatted, sanitised string.
 * Context is redacted, JSON-encoded and appended. Logging never throws: a broken logger must
 * not turn a successful registration into a reported failure.
 */
final class SafeLogger
{
    private const string PREFIX = '[OSIR] ';

    public function __construct(
        private readonly ?object $logger,
        private readonly bool $debugEnabled = false,
    ) {}

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * Debug lines are dropped unless the administrator enabled debug logging.
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->write('debug', $message, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        // Box_Log exposes its levels through __call, PSR-3 loggers as real methods; is_callable covers both.
        $callable = [$this->logger, $level];
        if ($this->logger === null || !is_callable($callable)) {
            return;
        }

        $line = self::PREFIX . Text::sanitize($message, 1000);
        if ($context !== []) {
            $json = json_encode(Redactor::redactArray($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($json)) {
                $line .= ' ' . Text::sanitize($json, 2000);
            }
        }

        try {
            $callable($line);
        } catch (\Throwable) {
            // Swallowed deliberately; see class comment.
        }
    }
}
