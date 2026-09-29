<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * The author of a Note (RFC 9553, section 2.8.3). At least one property must be set.
 */
final readonly class Author
{
    /**
     * @param array<array-key, mixed> $extra Other properties, as JSON values
     */
    public function __construct(
        public ?string $name = null,
        public ?string $uri = null,
        public array $extra = [],
    ) {
    }
}
