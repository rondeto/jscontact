<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Json;

/**
 * The properties of the Resource type (RFC 9553, section 1.4.4), as read from JSON.
 *
 * @internal
 */
final readonly class ResourceFields
{
    /**
     * @param list<string>                       $contexts
     * @param array<string, string|list<string>> $vCardParams
     * @param array<array-key, mixed>            $extra
     */
    public function __construct(
        public string $uri,
        public ?string $kind,
        public ?string $mediaType,
        public array $contexts,
        public ?int $pref,
        public ?string $label,
        public ?string $vCardName,
        public array $vCardParams,
        public array $extra,
    ) {
    }
}
