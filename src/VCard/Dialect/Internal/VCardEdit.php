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

    /**
     * The birthdays and wedding anniversaries without a year a JSPROP holds: vCard 3.0 has
     * no such dates, so the conversion writes them as JSPROP. Null if it holds anything else,
     * such as a place, which needs the JSPROP.
     *
     * @return array<string, array{string, int, int}>|null Kind, month and day, by key
     */
    public static function datesWithoutYear(Property $jsProp): ?array
    {
        $pointer = self::parts($jsProp, 'JSPTR')[0] ?? '';
        $value = json_decode((string) $jsProp, true);
        if ('anniversaries' === $pointer && \is_array($value)) {
            $anniversaries = $value;
        } elseif (1 === preg_match('~^anniversaries/([^/]+)$~', $pointer, $matches)) {
            $anniversaries = [strtr($matches[1], ['~1' => '/', '~0' => '~']) => $value];
        } else {
            return null;
        }

        $dates = [];
        foreach ($anniversaries as $key => $anniversary) {
            $date = \is_array($anniversary) ? ($anniversary['date'] ?? null) : null;
            $isDateWithoutYear = \is_array($date) && \is_int($date['month'] ?? null) && \is_int($date['day'] ?? null)
                && [] === array_diff(array_keys($date), ['@type', 'month', 'day']);
            $kind = \is_array($anniversary) ? ($anniversary['kind'] ?? null) : null;
            if (!$isDateWithoutYear || !\in_array($kind, ['birth', 'wedding'], true) || [] !== array_diff(array_keys($anniversary), ['@type', 'kind', 'date'])) {
                return null;
            }

            $dates[(string) $key] = [$kind, $date['month'], $date['day']];
        }

        return $dates;
    }
}
