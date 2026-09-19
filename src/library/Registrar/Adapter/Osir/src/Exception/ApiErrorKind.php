<?php

declare(strict_types=1);

namespace Osir\FossBilling\Exception;

/**
 * Normalised classification of an OSIR error response. OSIR uses several error body shapes and
 * reuses some status codes for unrelated conditions (e.g. 401 for both a bad API key and a bad
 * transfer auth code), so callers branch on this instead of raw status codes.
 */
enum ApiErrorKind: string
{
    /** The API key was rejected (missing, wrong, revoked, expired, IP not allowed). */
    case Authentication = 'authentication';
    /** Authenticated but not allowed (not the domain owner, endpoint not permitted for the key). */
    case Permission = 'permission';
    /** The OSIR account must verify its e-mail before billable actions. */
    case AccountNotVerified = 'account_not_verified';
    case NotFound = 'not_found';
    case InsufficientFunds = 'insufficient_funds';
    /** A per-key spend limit was reached (future-proof: sent by partner keys). */
    case SpendLimit = 'spend_limit';
    /** State conflict: domain not available, grace-period lock, transfer already exists, … */
    case Conflict = 'conflict';
    /** The same Idempotency-Key is still being processed; retry later with the SAME key. */
    case InProgress = 'in_progress';
    case RateLimited = 'rate_limited';
    /** The transfer authorisation (EPP) code was refused by the registry. */
    case InvalidAuthCode = 'invalid_auth_code';
    /** Request refused as invalid by OSIR or the registry (4xx). */
    case Rejected = 'rejected';
    /** OSIR or the registry failed (5xx). For writes, the outcome may be unknown. */
    case Server = 'server';
    /** A response that does not match the documented contract (not JSON, redirect, …). */
    case Protocol = 'protocol';

}
