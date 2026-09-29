<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account on an online service: messaging, social media… (RFC 9553, section 2.3.2).
 *
 * At least one of $uri and $user must be set.
 */
#[Constraint\AtLeastOneProperty(['uri', 'user'], 'an online service needs a uri, a user, or both')]
#[Constraint\ExtraProperties]
final readonly class OnlineService
{
    /**
     * @param string|null             $service  Name of the service or protocol, e.g. "Mastodon"
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        #[Assert\NotBlank(message: 'must not be empty', allowNull: true)]
        public ?string $service = null,
        #[Assert\Regex(Syntax::URI, message: '{{ value }} is not a URI')]
        public ?string $uri = null,
        public ?string $user = null,
        #[Constraint\RegisteredValue(Registry::CONTEXTS)]
        public array $contexts = [],
        #[Assert\Range(notInRangeMessage: 'must be between 1 and 100', min: 1, max: 100)]
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
