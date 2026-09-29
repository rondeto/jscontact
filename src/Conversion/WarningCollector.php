<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Conversion;

/**
 * @internal
 */
final class WarningCollector
{
    /** @var list<Warning> */
    private array $warnings = [];

    public function add(string $path, string $message): void
    {
        $this->warnings[] = new Warning($path, $message);
    }

    /**
     * @return list<Warning>
     */
    public function all(): array
    {
        return $this->warnings;
    }
}
