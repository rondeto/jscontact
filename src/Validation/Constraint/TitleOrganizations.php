<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The organizationId of a Title must be the key of one of the Card's organizations
 * (RFC 9553, section 2.2.5).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TitleOrganizations extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
