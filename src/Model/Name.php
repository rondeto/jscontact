<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The name of the entity a Card represents (RFC 9553, section 2.2.1.1).
 */
#[Constraint\AtLeastOneProperty(['components', 'full'], 'a name needs components, full, or both')]
#[Constraint\Components]
#[Constraint\SortAs]
#[Constraint\ExtraProperties]
final readonly class Name
{
    /**
     * @param list<NameComponent>                $components
     * @param array<array-key, string>           $sortAs      Verbatim sort value, by name component kind
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Assert\Valid]
        public array $components = [],
        public bool $isOrdered = false,
        public ?string $defaultSeparator = null,
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $full = null,
        public array $sortAs = [],
        #[Assert\Regex('/^[A-Za-z]{4}$/', message: 'not a script subtag')]
        public ?string $phoneticScript = null,
        #[Constraint\RegisteredValue(Registry::PHONETIC_SYSTEMS)]
        public ?string $phoneticSystem = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
