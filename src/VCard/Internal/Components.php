<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * Maps the components of N and ADR structured values to Name and Address components
 * (RFC 9555, sections 2.5.5, 2.6.1 and 3.3.1; RFC 9554, sections 2.1 and 2.2).
 *
 * @internal
 */
final class Components
{
    /** NameComponent kind of each N component. */
    public const array NAME_KINDS = ['surname', 'given', 'given2', 'title', 'credential', 'surname2', 'generation'];

    /** AddressComponent kind of each ADR component; 1 and 2 are the legacy extended and street address. */
    public const array ADDRESS_KINDS = [
        'postOfficeBox', 'apartment', 'name', 'locality', 'region', 'postcode', 'country', 'room', 'apartment',
        'floor', 'number', 'name', 'building', 'block', 'subdistrict', 'district', 'landmark', 'direction',
    ];

    /** Kinds combined in the legacy extended address (1) and street address (2) components. */
    public const array LEGACY_EXTENDED = ['room', 'floor', 'apartment', 'building'];

    public const array LEGACY_STREET = ['number', 'name', 'block', 'direction', 'landmark', 'subdistrict', 'district'];

    private function __construct()
    {
    }

    /**
     * The values of an N property, in reading order, without those repeated in a
     * backwards-compatible component: a family name also given as secondary surname, an
     * honorific suffix also given as generation.
     *
     * @param list<list<string>> $components
     *
     * @return list<array{kind: string, value: string, position: array{int, int}}>
     */
    public static function fromName(array $components): array
    {
        $values = [];
        foreach (self::NAME_KINDS as $index => $kind) {
            foreach ($components[$index] ?? [] as $subIndex => $value) {
                if ('' === $value
                    || (0 === $index && \in_array($value, $components[5] ?? [], true))
                    || (4 === $index && \in_array($value, $components[6] ?? [], true))) {
                    continue;
                }

                $values[] = ['kind' => $kind, 'value' => $value, 'position' => [$index, $subIndex]];
            }
        }

        return $values;
    }

    /**
     * The values of an ADR property, in reading order. When any of the components added by
     * RFC 9554 is set, the legacy extended and street address components are ignored.
     *
     * @param list<list<string>> $components
     *
     * @return list<array{kind: string, value: string, position: array{int, int}}>
     */
    public static function fromAddress(array $components): array
    {
        $isExtended = false;
        foreach (\array_slice($components, 7) as $values) {
            $isExtended = $isExtended || [] !== array_filter($values, static fn (string $value): bool => '' !== $value);
        }

        $values = [];
        foreach (self::ADDRESS_KINDS as $index => $kind) {
            if ($isExtended && (1 === $index || 2 === $index)) {
                continue;
            }

            foreach ($components[$index] ?? [] as $subIndex => $value) {
                if ('' !== $value) {
                    $values[] = ['kind' => $kind, 'value' => $value, 'position' => [$index, $subIndex]];
                }
            }
        }

        return $values;
    }

    /**
     * Orders values as a JSCOMPS parameter says (RFC 9555, section 3.3.1).
     *
     * @param list<array{kind: string, value: string, position: array{int, int}}> $values
     * @param list<list<string>>                                                  $components
     * @param list<string>                                                        $kinds      Kind of each component index
     *
     * @return array{defaultSeparator: string|null, components: list<array{kind: string, value: string, position: array{int, int}|null}>}|null null if the parameter is not valid
     */
    public static function order(string $jsComps, array $values, array $components, array $kinds): ?array
    {
        $entries = self::splitEscaped($jsComps);
        $first = array_shift($entries);
        if (null === $first || ('' !== $first && !str_starts_with($first, 's,'))) {
            return null;
        }

        $defaultSeparator = '' === $first ? null : VCardText::unescape(substr($first, 2));

        $ordered = [];
        $positionals = 0;
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 's,')) {
                $ordered[] = ['kind' => 'separator', 'value' => VCardText::unescape(substr($entry, 2)), 'position' => null];
                continue;
            }

            if (1 !== preg_match('/^(\d+)(?:,([1-9]\d*))?$/', $entry, $matches)) {
                return null;
            }

            $index = (int) $matches[1];
            $subIndex = (int) ($matches[2] ?? 0);
            $value = $components[$index][$subIndex] ?? null;
            if (null === $value || !isset($kinds[$index])) {
                return null;
            }

            $ordered[] = ['kind' => $kinds[$index], 'value' => $value, 'position' => [$index, $subIndex]];
            ++$positionals;
        }

        if ($positionals !== \count($values)) {
            return null;
        }

        return ['defaultSeparator' => $defaultSeparator, 'components' => $ordered];
    }

    /**
     * Builds a JSCOMPS parameter value.
     *
     * @param list<array{int, int}|string> $entries Positions of values, or verbatim separators
     */
    public static function jsComps(?string $defaultSeparator, array $entries): string
    {
        $parts = [null === $defaultSeparator ? '' : 's,'.VCardText::escape($defaultSeparator)];
        foreach ($entries as $entry) {
            $parts[] = \is_string($entry) ? 's,'.VCardText::escape($entry) : $entry[0].(0 === $entry[1] ? '' : ','.$entry[1]);
        }

        return implode(';', $parts);
    }

    /**
     * Splits on semicolons that are not escaped, keeping escapes.
     *
     * @return list<string>
     */
    private static function splitEscaped(string $value): array
    {
        return preg_split('/(?<!\\\\);/', $value) ?: [];
    }
}
