<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * The name of the entity a Card represents (RFC 9553, section 2.2.1.1).
 */
final readonly class Name
{
    /**
     * @param list<NameComponent>      $components
     * @param array<array-key, string> $sortAs     Verbatim sort value, by name component kind
     * @param array<array-key, mixed>  $extra      Other properties, as JSON values
     */
    public function __construct(
        public array $components = [],
        public bool $isOrdered = false,
        public ?string $defaultSeparator = null,
        public ?string $full = null,
        public array $sortAs = [],
        public ?string $phoneticScript = null,
        public ?string $phoneticSystem = null,
        public array $extra = [],
    ) {
    }
}
