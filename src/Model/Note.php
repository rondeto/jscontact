<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A free-text note (RFC 9553, section 2.8.3).
 */
#[Constraint\ExtraProperties]
final readonly class Note
{
    /**
     * @param array<array-key, mixed> $extra Other properties, as JSON values
     */
    public function __construct(
        public string $note,
        public ?\DateTimeImmutable $created = null,
        #[Assert\Valid]
        public ?Author $author = null,
        public array $extra = [],
    ) {
    }
}
