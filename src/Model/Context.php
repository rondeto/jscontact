<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * Registered values for the "contexts" property (RFC 9553, section 1.5.1).
 *
 * Contexts are plain strings: vendor-specific values ("example.com:foo") and values
 * registered after this library was written are valid too.
 */
final class Context
{
    public const string PRIVATE = 'private';

    public const string WORK = 'work';

    /** Only for addresses. */
    public const string BILLING = 'billing';

    /** Only for addresses. */
    public const string DELIVERY = 'delivery';

    private function __construct()
    {
    }
}
