<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

final class InvalidCardException extends \InvalidArgumentException
{
    /**
     * @param non-empty-list<Violation> $violations
     */
    public function __construct(
        public readonly array $violations,
    ) {
        parent::__construct("The card is not valid JSContact:\n- ".implode("\n- ", array_map(strval(...), $violations)));
    }
}
