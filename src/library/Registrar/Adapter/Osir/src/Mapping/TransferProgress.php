<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

/**
 * An answer to "where is this transfer?": the state, and one sentence for the staff member who
 * reads it in the order's history. The sentence is composed from OSIR's structured fields rather
 * than copied from its message, which can carry account details.
 */
final class TransferProgress
{
    public function __construct(
        public readonly TransferState $state,
        public readonly string $message,
    ) {}
}
