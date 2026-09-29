<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A postal address or geographic location (RFC 9553, section 2.5.1.1).
 */
#[Constraint\AtLeastOneProperty(['components', 'coordinates', 'countryCode', 'full', 'timeZone'], 'an address needs at least one of components, coordinates, countryCode, full or timeZone')]
#[Constraint\Components]
#[Constraint\ExtraProperties]
final readonly class Address
{
    /**
     * @param list<AddressComponent>  $components
     * @param string|null             $countryCode ISO 3166-1 alpha-2 code
     * @param string|null             $coordinates A "geo:" URI
     * @param string|null             $timeZone    An IANA Time Zone Database name
     * @param list<string>            $contexts
     * @param int|null                $pref        1 (most preferred) to 100
     * @param array<array-key, mixed> $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Assert\Valid]
        public array $components = [],
        public bool $isOrdered = false,
        #[Assert\Regex('/^[A-Za-z]{2}$/', message: 'not an ISO 3166-1 alpha-2 code')]
        public ?string $countryCode = null,
        #[Assert\Regex('/^geo:/i', message: 'not a "geo:" URI')]
        public ?string $coordinates = null,
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $timeZone = null,
        #[Constraint\RegisteredValue(Registry::ADDRESS_CONTEXTS)]
        public array $contexts = [],
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $full = null,
        public ?string $defaultSeparator = null,
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 100', min: 1, max: 100)]
        public ?int $pref = null,
        #[Assert\Regex('/^[A-Za-z]{4}$/', message: 'not a script subtag')]
        public ?string $phoneticScript = null,
        #[Constraint\RegisteredValue(Registry::PHONETIC_SYSTEMS)]
        public ?string $phoneticSystem = null,
        public array $extra = [],
    ) {
    }
}
