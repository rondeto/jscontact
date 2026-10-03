<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Card::$localizations: language tags, and PatchObjects that are valid and give a valid
 * Card (RFC 9553, sections 1.4.3 and 2.7.1).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Localizations extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
