<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Conversion;

/**
 * @internal
 */
final class IssueCollector
{
    /** @var list<Issue> */
    private array $issues = [];

    public function add(string $path, string $message): void
    {
        $this->issues[] = new Issue($path, $message);
    }

    /**
     * @return list<Issue>
     */
    public function all(): array
    {
        return $this->issues;
    }
}
