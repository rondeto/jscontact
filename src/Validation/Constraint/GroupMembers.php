<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Only a group Card can have members (RFC 9553, section 2.1.6).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class GroupMembers extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
