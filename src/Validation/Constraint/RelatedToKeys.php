<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The keys of Card::$relatedTo are the uids of other Cards (RFC 9553, section 2.1.8): any
 * non-empty string, not necessarily an Id.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class RelatedToKeys extends Constraint
{
}
