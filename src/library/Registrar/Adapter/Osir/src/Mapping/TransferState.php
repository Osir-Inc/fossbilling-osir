<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

/**
 * Where an incoming transfer stands, in the three outcomes a billing platform can act on.
 *
 * The values are deliberately the ones ClientFence's Registrar_TransferStatus uses, but nothing
 * here depends on that interface: the adapter does the translation.
 */
enum TransferState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
