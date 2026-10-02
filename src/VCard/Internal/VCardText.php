<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * Escaping and splitting of vCard text values (RFC 6350, sections 3.4 and 4.1).
 *
 * sabre/vobject unescapes structured values before splitting their lists, so it cannot tell
 * "a\,b" (one value) from "a,b" (two values): structured values are split here instead.
 *
 * @internal
 */
final class VCardText
{
    private function __construct()
    {
    }

    /**
     * Splits a raw structured value (N, ADR…) into its components, and each component into
     * its list values. Empty components are empty lists.
     *
     * @return list<list<string>>
     */
    public static function splitStructured(string $raw): array
    {
        return array_map(
            static fn (string $component): array => '' === $component ? [] : array_map(self::unescape(...), self::split($component, ',')),
            self::split($raw, ';'),
        );
    }

    /**
     * Builds a raw structured value from components and their list values.
     *
     * @param list<list<string>> $components
     */
    public static function joinStructured(array $components): string
    {
        return implode(';', array_map(
            static fn (array $values): string => implode(',', array_map(self::escape(...), $values)),
            $components,
        ));
    }

    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', ',' => '\,', ';' => '\;', "\r\n" => '\n', "\n" => '\n']);
    }

    public static function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', static fn (array $matches): string => match ($matches[1]) {
            'n', 'N' => "\n",
            default => $matches[1],
        }, $value) ?? $value;
    }

    /**
     * Splits on a delimiter that is not escaped with a backslash, keeping the escapes.
     *
     * @return list<string>
     */
    private static function split(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $length = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $current .= $char.$value[++$i];
            } elseif ($char === $delimiter) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $parts[] = $current;

        return $parts;
    }
}
