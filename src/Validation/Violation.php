<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

/**
 * A rule of the JSContact specification that a Card breaks.
 */
final readonly class Violation implements \Stringable
{
    /**
     * @param string $path    The offending value, as a JSON Pointer into the Card's JSON form
     * @param string $message Which rule is broken
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
