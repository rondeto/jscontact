<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Sabre\VObject\Component\VCard;

/**
 * @internal
 */
final readonly class ParsedCard
{
    /**
     * @param array<string, list<string>> $rawValues Raw values of each property, by uppercase name, in order
     * @param list<string>                $issues
     */
    public function __construct(
        public ?VCard $vCard,
        public array $rawValues,
        public array $issues,
    ) {
    }
}
