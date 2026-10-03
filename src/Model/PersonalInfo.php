<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An expertise, hobby or interest (RFC 9553, section 2.8.4).
 */
#[Constraint\ExtraProperties]
final class PersonalInfo
{
    public const string KIND_EXPERTISE = 'expertise';

    public const string KIND_HOBBY = 'hobby';

    public const string KIND_INTEREST = 'interest';

    public const string LEVEL_HIGH = 'high';

    public const string LEVEL_MEDIUM = 'medium';

    public const string LEVEL_LOW = 'low';

    /**
     * @param int|null                           $listAs      Position among the personal information of the same kind
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::PERSONAL_INFO_KINDS)]
        public string $kind,
        #[Assert\NotBlank(message: 'must not be empty')]
        public string $value,
        #[Constraint\RegisteredValue(Registry::PERSONAL_INFO_LEVELS)]
        public ?string $level = null,
        #[Assert\Positive(message: 'must be greater than 0')]
        public ?int $listAs = null,
        public ?string $label = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
