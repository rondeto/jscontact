<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The author of a Note (RFC 9553, section 2.8.3). At least one property must be set.
 */
#[Constraint\AtLeastOneProperty(['name', 'uri', 'extra'], 'an author needs at least one property')]
#[Constraint\ExtraProperties]
final readonly class Author
{
    /**
     * @param string|null                        $vCardName   The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra       Other properties, as JSON values
     */
    public function __construct(
        public ?string $name = null,
        #[Assert\Regex(Syntax::URI, message: '{{ value }} is not a URI')]
        public ?string $uri = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
