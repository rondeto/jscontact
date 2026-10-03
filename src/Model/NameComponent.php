<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One part of a Name (RFC 9553, section 2.2.1.2).
 */
#[Constraint\ExtraProperties]
final class NameComponent
{
    public const string KIND_TITLE = 'title';

    public const string KIND_GIVEN = 'given';

    public const string KIND_GIVEN2 = 'given2';

    public const string KIND_SURNAME = 'surname';

    public const string KIND_SURNAME2 = 'surname2';

    public const string KIND_CREDENTIAL = 'credential';

    public const string KIND_GENERATION = 'generation';

    public const string KIND_SEPARATOR = 'separator';

    /**
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::NAME_COMPONENT_KINDS)]
        public string $kind,
        public string $value,
        public ?string $phonetic = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
