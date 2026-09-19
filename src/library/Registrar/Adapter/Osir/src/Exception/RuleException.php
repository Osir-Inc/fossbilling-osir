<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * The adapter refused an operation on purpose (premium name, cost above the configured cap,
 * domain in a state that cannot be renewed, …). The message is written for the end client and
 * contains no account details.
 */
final class RuleException extends OsirException {}
