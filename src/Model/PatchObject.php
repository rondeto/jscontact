<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

/**
 * Changes to apply to a Card, such as a localization (RFC 9553, section 1.4.3).
 *
 * Each key is a path relative to the Card, like "titles/t1/name" or "name/components/0/value";
 * each value is the JSON value to set there, or null to remove it.
 */
final class PatchObject
{
    /**
     * @param array<array-key, mixed> $patches Values by path: a JSON pointer without its leading "/", relative to the Card
     */
    public function __construct(
        public array $patches,
    ) {
    }
}
