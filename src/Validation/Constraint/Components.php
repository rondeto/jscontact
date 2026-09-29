<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The rules on the components of a Name or an Address (RFC 9553, sections 2.2.1.1,
 * 2.5.1.1 and 1.5.4): separators, default separator and phonetics.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Components extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
