<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The keys of an Id[Object] map must be Ids (RFC 9553, section 1.4.1).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class IdKeys extends Constraint
{
}
