<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A whole or partial date in the Gregorian calendar: a full date, a year, a month of a year,
 * or a day of a month (RFC 9553, section 2.8.1).
 */
#[Constraint\PartialDateParts]
#[Constraint\ExtraProperties]
final class PartialDate
{
    /**
     * @param string|null                        $calendarScale The calendar system the date occurs in, such as "hebrew"; year, month and day stay Gregorian
     * @param string|null                        $vCardName     The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams   vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra         Other properties, as JSON values
     */
    public function __construct(
        #[Assert\PositiveOrZero(message: 'must not be negative')]
        public ?int $year = null,
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 12', min: 1, max: 12)]
        public ?int $month = null,
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 31', min: 1, max: 31)]
        public ?int $day = null,
        #[Assert\Regex('/^[^A-Z]+$/', message: 'must be lowercase')]
        public ?string $calendarScale = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
