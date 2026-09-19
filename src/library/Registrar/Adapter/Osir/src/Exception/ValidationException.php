<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * Input rejected locally, before any request is sent to OSIR.
 * The message is safe to show to the client (it never contains secrets or raw API output).
 */
final class ValidationException extends OsirException {}
