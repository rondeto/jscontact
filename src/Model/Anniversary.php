<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A memorable date: birth, death, wedding… (RFC 9553, section 2.8.1).
 */
#[Constraint\ExtraProperties]
final class Anniversary
{
    public const string KIND_BIRTH = 'birth';

    public const string KIND_DEATH = 'death';

    public const string KIND_WEDDING = 'wedding';

    /**
     * @param Address|null                       $place       Where it happened, such as the place of birth
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::ANNIVERSARY_KINDS)]
        public string $kind,
        #[Assert\Valid]
        public PartialDate|Timestamp $date,
        #[Assert\Valid]
        public ?Address $place = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
