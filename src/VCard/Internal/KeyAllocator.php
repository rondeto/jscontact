<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * Chooses the Id of each entry of a JSContact map converted from vCard properties.
 *
 * A valid PROP-ID is used as is (RFC 9555, section 2.3.18). Otherwise, the key is named
 * after the vCard property and its position among the properties of the same kind, as in
 * the examples of RFC 9555: "EMAIL-1", "EMAIL-2"… Positions count every property of the
 * kind, with or without PROP-ID, converted or not, so that a key does not depend on the
 * other properties.
 *
 * @internal
 */
final class KeyAllocator
{
    /** @var array<string, array<string, true>> Keys taken, by map */
    private array $taken = [];

    /** @var array<string, int> */
    private array $positions = [];

    /**
     * Reserves PROP-ID values before any key is generated, so that a generated key never
     * takes the PROP-ID of a later property.
     */
    public function reserve(string $map, string $propId): void
    {
        $this->taken[$map][$propId] = true;
    }

    public function next(string $map, string $prefix): string
    {
        $position = $this->positions[$prefix] = ($this->positions[$prefix] ?? 0) + 1;
        $key = $prefix.'-'.$position;
        for ($suffix = 2; isset($this->taken[$map][$key]); ++$suffix) {
            $key = $prefix.'-'.$position.'-'.$suffix;
        }

        $this->taken[$map][$key] = true;

        return $key;
    }

    /**
     * Counts a property that gets no generated key (it has its own PROP-ID, or is kept
     * verbatim), so that the positions of the others do not depend on it.
     */
    public function skip(string $prefix): void
    {
        $this->positions[$prefix] = ($this->positions[$prefix] ?? 0) + 1;
    }
}
