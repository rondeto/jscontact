<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * A link to a resource that fits no more specific property (RFC 9553, section 2.6.3).
 */
final readonly class Link
{
    public const string KIND_CONTACT = 'contact';

    /**
     * @param list<string>            $contexts
     * @param int|null                $pref     1 (most preferred) to 100
     * @param array<array-key, mixed> $extra    Other properties, as JSON values
     */
    public function __construct(
        public string $uri,
        public ?string $kind = null,
        public ?string $mediaType = null,
        public array $contexts = [],
        public ?int $pref = null,
        public ?string $label = null,
        public array $extra = [],
    ) {
    }
}
