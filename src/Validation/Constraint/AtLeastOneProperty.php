<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * At least one of the given properties must be set (not null, not empty).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AtLeastOneProperty extends Constraint
{
    /**
     * @param non-empty-list<string> $properties
     * @param list<string>|null      $groups
     */
    public function __construct(
        public readonly array $properties,
        public readonly string $message,
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
