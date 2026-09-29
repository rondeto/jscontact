<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Conversion;

/**
 * A problem with a Card: a value that could not be read or had to be corrected, or a rule
 * of the specification that the Card breaks.
 */
final readonly class Issue implements \Stringable
{
    /**
     * @param string $path    Where the problem is, as a JSON Pointer into the Card's JSON form
     * @param string $message What is wrong, and what was done about it if anything
     */
    public function __construct(
        public string $path,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return ('' === $this->path ? '/' : $this->path).': '.$this->message;
    }
}
