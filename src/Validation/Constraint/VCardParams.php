<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * vCard parameters in their jCard form (RFC 7095, section 3.4): lowercase names, and
 * string or list of strings values.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class VCardParams extends Constraint
{
}
