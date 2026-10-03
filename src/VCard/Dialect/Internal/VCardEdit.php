<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect\Internal;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;

/**
 * Rewriting vCard properties, for dialects.
 *
 * @internal
 */
final class VCardEdit
{
    private function __construct()
    {
    }

    /**
     * Replaces a property with another, in the same group.
     *
     * @param array<string, list<string>> $params
     */
    public static function replace(VCard $vCard, Property $property, string $name, string $value, array $params): Property
    {
        $vCard->remove($property);
        // sabre/vobject reads the VALUE parameter as a string.
        $params = array_map(static fn (array $values): string|array => 1 === \count($values) ? $values[0] : $values, $params);
        $replacement = $vCard->createProperty($name, $value, $params);
        $replacement->group = $property->group;

        $vCard->add($replacement);

        return $replacement;
    }

    /**
     * @param list<string> $except Uppercase names of parameters to leave out
     *
     * @return array<string, list<string>>
     */
    public static function parameters(Property $property, array $except = []): array
    {
        $params = [];
        foreach ($property->parameters() as $parameter) {
            $name = strtoupper((string) $parameter->name);
            if (!\in_array($name, $except, true)) {
                $params[$name] = self::parts($property, $name);
            }
        }

        return $params;
    }

    /**
     * @return list<string> The values of a parameter, none if it is not set
     */
    public static function parts(Property $property, string $name): array
    {
        $parts = [];
        foreach ($property->parameters() as $parameter) {
            if (0 === strcasecmp((string) $parameter->name, $name)) {
                foreach ($parameter->getParts() as $part) {
                    $parts[] = (string) $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @return list<Property>
     */
    public static function properties(VCard $vCard): array
    {
        return array_values(array_filter($vCard->children(), static fn (mixed $child): bool => $child instanceof Property));
    }
}
