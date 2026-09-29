<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An email address (RFC 9553, section 2.3.1).
 */
#[Constraint\ExtraProperties]
final readonly class EmailAddress
{
    /**
     * @param string                  $address  An RFC 5322 addr-spec
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        #[Assert\Regex(Syntax::EMAIL_ADDRESS, message: '{{ value }} is not an email address')]
        public string $address,
        #[Constraint\RegisteredValue(Registry::CONTEXTS)]
        public array $contexts = [],
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 100', min: 1, max: 100)]
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
