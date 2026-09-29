<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Each key of Name::$sortAs must be the kind of one of its components (RFC 9553,
 * section 2.2.1.1).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SortAs extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
