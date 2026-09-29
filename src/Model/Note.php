<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A free-text note (RFC 9553, section 2.8.3).
 */
final readonly class Note
{
    /**
     * @param array<array-key, mixed> $extra Other properties, as JSON values
     */
    public function __construct(
        public string $note,
        public ?\DateTimeImmutable $created = null,
        public ?Author $author = null,
        public array $extra = [],
    ) {
    }
}
