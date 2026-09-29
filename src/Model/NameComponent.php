<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;

/**
 * One part of a Name (RFC 9553, section 2.2.1.2).
 */
#[Constraint\ExtraProperties]
final readonly class NameComponent
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
     * @param array<array-key, mixed> $extra Other properties, as JSON values
     */
    public function __construct(
        #[Constraint\RegisteredValue(Registry::NAME_COMPONENT_KINDS)]
        public string $kind,
        public string $value,
        public ?string $phonetic = null,
        public array $extra = [],
    ) {
    }
}
