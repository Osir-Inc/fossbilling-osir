<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * Base of every exception raised inside the adapter.
 *
 * Messages are written as FOSSBilling translation templates (":placeholder" variables) and
 * must never embed secrets, auth codes or raw upstream payloads. The adapter boundary
 * converts these into Registrar_Exception using {@see self::getTemplate()} and
 * {@see self::getVariables()}.
 */
abstract class OsirException extends \RuntimeException
{
    /**
     * @param array<string, string> $variables translation variables, e.g. [':domain' => 'example.com']
     */
    public function __construct(
        private readonly string $template,
        private readonly array $variables = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(strtr($template, $variables), $code, $previous);
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    /** @return array<string, string> */
    public function getVariables(): array
    {
        return $this->variables;
    }
}
