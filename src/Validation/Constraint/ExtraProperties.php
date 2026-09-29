<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraint;

/**
 * The names of the properties kept verbatim in an object's $extra array
 * (RFC 9553, sections 1.7 and 1.8.1).
 *
 * They must be valid IANA or vendor-specific property names, must not be a property the
 * object models, nor a case variant of a registered one.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ExtraProperties extends Constraint
{
    /**
     * @param list<string>      $registered Registered properties the object may hold unmodeled
     * @param list<string>|null $groups
     */
    public function __construct(
        public readonly array $registered = Registry::UNMODELED_COMMON_PROPERTIES,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
