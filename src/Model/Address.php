<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A postal address or geographic location (RFC 9553, section 2.5.1.1).
 */
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
        public array $components = [],
        public bool $isOrdered = false,
        public ?string $countryCode = null,
        public ?string $coordinates = null,
        public ?string $timeZone = null,
        public array $contexts = [],
        public ?string $full = null,
        public ?string $defaultSeparator = null,
        public ?int $pref = null,
        public ?string $phoneticScript = null,
        public ?string $phoneticSystem = null,
        public array $extra = [],
    ) {
    }
}
