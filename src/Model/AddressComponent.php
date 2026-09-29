<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;

/**
 * One part of an Address (RFC 9553, section 2.5.1.2).
 */
#[Constraint\ExtraProperties]
final readonly class AddressComponent
{
    public const string KIND_ROOM = 'room';

    public const string KIND_APARTMENT = 'apartment';

    public const string KIND_FLOOR = 'floor';

    public const string KIND_BUILDING = 'building';

    public const string KIND_NUMBER = 'number';

    public const string KIND_NAME = 'name';

    public const string KIND_BLOCK = 'block';

    public const string KIND_SUBDISTRICT = 'subdistrict';

    public const string KIND_DISTRICT = 'district';

    public const string KIND_LOCALITY = 'locality';

    public const string KIND_REGION = 'region';

    public const string KIND_POSTCODE = 'postcode';

    public const string KIND_COUNTRY = 'country';

    public const string KIND_DIRECTION = 'direction';

    public const string KIND_LANDMARK = 'landmark';

    public const string KIND_POST_OFFICE_BOX = 'postOfficeBox';

    public const string KIND_SEPARATOR = 'separator';

    /**
     * @param array<array-key, mixed> $extra Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::ADDRESS_COMPONENT_KINDS)]
        public string $kind,
        public string $value,
        public ?string $phonetic = null,
        public array $extra = [],
    ) {
    }
}
