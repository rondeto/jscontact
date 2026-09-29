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
     * @param T           $value
     * @param list<Issue> $issues
     */
    public function __construct(
        public mixed $value,
        public array $issues = [],
    ) {
    }

    public function hasIssues(): bool
    {
        return [] !== $this->issues;
    }
}
