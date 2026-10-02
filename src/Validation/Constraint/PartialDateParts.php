<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * A PartialDate is a full date, a year, a month of a year, or a day of a month (RFC 9553,
 * section 2.8.1), and the day must exist in its month.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PartialDateParts extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
