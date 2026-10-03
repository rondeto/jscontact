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

    /**
     * @return int The index of the issue in all()
     */
    public function add(string $path, string $message): int
    {
        $this->issues[] = new Issue($path, $message);

        return \count($this->issues) - 1;
    }

    /**
     * @return list<Issue>
     */
    public function all(): array
    {
        return $this->issues;
    }
}
