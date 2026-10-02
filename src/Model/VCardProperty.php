<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Model;

use Rondeto\JSContact\Validation\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A vCard property kept verbatim in Card::$vCardProps, in its jCard form (RFC 7095,
 * section 3.3; RFC 9555, section 2.15.1).
 */
final readonly class VCardProperty
{
    /**
     * @param string                             $name       The lowercase property name, e.g. "x-foo"
     * @param array<string, string|list<string>> $parameters Lowercase parameter names, including "group"
     * @param string                             $type       The jCard value type, e.g. "text" or "unknown"
     * @param non-empty-list<mixed>              $values     The jCard values, as JSON values
     */
    public function __construct(
        #[Assert\Regex('/^[a-z0-9-]+$/', message: 'not a lowercase vCard property name')]
        public string $name,
        #[Constraint\VCardParams]
        public array $parameters,
        #[Assert\Regex('/^[a-z0-9-]+$/', message: 'not a lowercase jCard value type')]
        public string $type,
        #[Assert\Count(min: 1, minMessage: 'a jCard property needs at least one value')]
        public array $values,
    ) {
    }
}
