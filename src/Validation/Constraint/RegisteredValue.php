<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * An enumerated value, or a set of them (RFC 9553, section 1.7.5).
 *
 * Any non-empty value is allowed, as vendors and later versions may add values, except
 * one that only differs in case from a registered value (RFC 9553, section 1.7.1). With no
 * registered values, it only checks that values are not empty.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class RegisteredValue extends Constraint
{
    /**
     * @param list<string>      $values The registered values
     * @param list<string>|null $groups
     */
    public function __construct(
        public readonly array $values = [],
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
