<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Conversion;

/**
 * The outcome of a conversion: the converted value and the problems met on the way.
 *
 * @template-covariant T
 */
final readonly class Result
{
    /**
     * @param T             $value
     * @param list<Warning> $warnings
     */
    public function __construct(
        public mixed $value,
        public array $warnings = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }
}
