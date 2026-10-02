<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How to address and refer to the entity a Card represents (RFC 9553, section 2.2.4).
 *
 * At least one of $grammaticalGender and $pronouns must be set.
 */
#[Constraint\AtLeastOneProperty(['grammaticalGender', 'pronouns'], 'needs a grammatical gender, pronouns, or both')]
#[Constraint\ExtraProperties]
final readonly class SpeakToAs
{
    public const string GENDER_ANIMATE = 'animate';

    public const string GENDER_COMMON = 'common';

    public const string GENDER_FEMININE = 'feminine';

    public const string GENDER_INANIMATE = 'inanimate';

    public const string GENDER_MASCULINE = 'masculine';

    public const string GENDER_NEUTER = 'neuter';

    /**
     * @param string|null                        $grammaticalGender For salutations and other grammatical constructs; it says nothing of gender identity
     * @param array<array-key, Pronouns>         $pronouns
     * @param string|null                        $vCardName         The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams       vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra             Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::GRAMMATICAL_GENDERS)]
        public ?string $grammaticalGender = null,
        #[Constraint\IdKeys, Assert\Valid]
        public array $pronouns = [],
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
