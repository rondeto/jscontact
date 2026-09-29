<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Conversion;

/**
 * Something a conversion could not read, or had to correct, without failing the whole card.
 */
final readonly class Warning implements \Stringable
{
    /**
     * @param string $path    Where the problem is, as a JSON Pointer into the source document
     * @param string $message What went wrong and what was done about it
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
