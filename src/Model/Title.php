<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A job title or functional position (RFC 9553, section 2.2.5).
 */
#[Constraint\ExtraProperties]
final class Title
{
    public const string KIND_TITLE = 'title';

    public const string KIND_ROLE = 'role';

    /**
     * @param string|null                        $kind           null means "title", the default
     * @param string|null                        $organizationId The key, in Card::$organizations, of the organization the title is held in
     * @param string|null                        $vCardName      The name of the vCard property this object was converted from (RFC 9555, section 2.15.3)
     * @param array<string, string|list<string>> $vCardParams    vCard parameters this object has no property for, in jCard form (RFC 9555, section 2.15.2)
     * @param array<array-key, mixed>            $extra          Other properties, as JSON values
     */
    public function __construct(
        #[Assert\NotBlank(message: 'must not be empty')]
        public string $name,
        #[Constraint\RegisteredValue(Registry::TITLE_KINDS)]
        public ?string $kind = null,
        #[Assert\Regex(Syntax::ID, message: 'not a valid Id')]
        public ?string $organizationId = null,
        #[Assert\Regex('/^[A-Za-z0-9-]+$/', message: 'not a vCard property name')]
        public ?string $vCardName = null,
        #[Constraint\VCardParams]
        public array $vCardParams = [],
        public array $extra = [],
    ) {
    }
}
