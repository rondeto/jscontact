<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * What all entries converted from a vCard property have in common.
 *
 * @internal
 */
final readonly class Common
{
    /**
     * @param list<string>                       $contexts
     * @param array<string, string|list<string>> $vCardParams
     */
    public function __construct(
        public string $key,
        public array $contexts,
        public ?int $pref,
        public ?string $label,
        public ?string $vCardName,
        public array $vCardParams,
    ) {
    }
}
