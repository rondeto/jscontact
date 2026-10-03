<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company or organization, with its units (RFC 9553, section 2.2.3).
 *
 * At least one of $name and $units must be set.
 */
#[Constraint\AtLeastOneProperty(['name', 'units'], 'an organization needs a name, units, or both')]
#[Constraint\ExtraProperties]
final class Organization
{
    /**
     * @param list<OrgUnit>                      $units       Ordered from the top of the hierarchy down
     * @param string|null                        $sortAs      Verbatim value to sort the organization by name
     * @param list<string>                       $contexts
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $name = null,
        #[Assert\Valid]
        public array $units = [],
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $sortAs = null,
        #[Constraint\RegisteredValue(Registry::CONTEXTS)]
        public array $contexts = [],
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
