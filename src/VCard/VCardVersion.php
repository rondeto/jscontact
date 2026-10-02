<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard;

/**
 * The vCard versions this library writes.
 */
enum VCardVersion: string
{
    /** RFC 2426. */
    case V30 = '3.0';

    /** RFC 6350, with the extensions of RFC 9554. */
    case V40 = '4.0';
}
