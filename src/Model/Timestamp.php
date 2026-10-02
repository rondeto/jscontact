<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A point in time, for an Anniversary (RFC 9553, section 2.8.1).
 */
#[Constraint\ExtraProperties]
final readonly class Timestamp
{
    /**
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        public \DateTimeImmutable $utc,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
