<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * An email address (RFC 9553, section 2.3.1).
 */
final readonly class EmailAddress
{
    /**
     * @param string                  $address  An RFC 5322 addr-spec
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        public string $address,
        public array $contexts = [],
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
