<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A nickname (RFC 9553, section 2.2.2).
 */
final readonly class Nickname
{
    /**
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        public string $name,
        public array $contexts = [],
        public ?int $pref = null,
        public array $extra = [],
    ) {
    }
}
