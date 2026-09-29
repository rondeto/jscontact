<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

use Rondeto\JSContact\Conversion\Issue;

/**
 * A Card breaks the specification: thrown when writing it, or when reading it strictly.
 */
final class InvalidCardException extends \InvalidArgumentException
{
    /**
     * @param non-empty-list<Issue> $issues
     */
    public function __construct(
        public readonly array $issues,
    ) {
        parent::__construct("The card is not valid JSContact:\n- ".implode("\n- ", array_map(strval(...), $issues)));
    }
}
