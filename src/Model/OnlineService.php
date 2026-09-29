<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * An account on an online service: messaging, social media… (RFC 9553, section 2.3.2).
 *
 * At least one of $uri and $user must be set.
 */
final readonly class OnlineService
{
    /**
     * @param string|null             $service  Name of the service or protocol, e.g. "Mastodon"
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        public ?string $service = null,
        public ?string $uri = null,
        public ?string $user = null,
        public array $contexts = [],
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
