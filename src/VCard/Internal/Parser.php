<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * Reads vCard text leniently, one card at a time, with sabre/vobject.
 *
 * Before handing each card to sabre, it reports the lines sabre would silently drop,
 * removes repeated VALUE parameters (sabre/vobject 5.0 crashes on them), and keeps the
 * raw value of each property: sabre cannot tell escaped commas in N and ADR from list
 * separators, and loses the escapes of unknown properties, which jCard keeps as is.
 *
 * @internal
 */
final class Parser
{
    private function __construct()
    {
    }

    /**
     * @return list<ParsedCard>
     */
    public static function parse(string $text): array
    {
        $cards = [];
        $lines = null;
        foreach (self::logicalLines($text) as $number => $line) {
            $name = strtoupper(trim($line));
            if ('BEGIN:VCARD' === $name) {
                $lines = [$number => $line];
            } elseif (null !== $lines) {
                $lines[$number] = $line;
                if ('END:VCARD' === $name) {
                    $cards[] = self::parseCard($lines);
                    $lines = null;
                }
            }
        }

        return $cards;
    }

    /**
     * @param non-empty-array<int, string> $lines Logical lines, by line number in the input
     */
    private static function parseCard(array $lines): ParsedCard
    {
        $issues = [];
        $raw = [];
        $kept = [];
        foreach ($lines as $number => $line) {
            if ('' === trim($line)) {
                continue;
            }

            $colon = self::valueSeparator($line);
            if (null === $colon) {
                // sabre/vobject workaround: with OPTION_IGNORE_INVALID_LINES, sabre drops such
                // lines without telling, or reads them as a property named after their start.
                $issues[] = \sprintf('line %d is not a vCard property, ignored it', $number);
                continue;
            }

            $head = substr($line, 0, $colon);
            $parts = explode(';', $head);
            $name = strtoupper((string) preg_replace('/^.*\./', '', $parts[0]));

            // sabre/vobject workaround: a repeated VALUE parameter makes sabre 5.0 throw a
            // TypeError (Document::getClassNameForPropertyValue() gets an array).
            $values = preg_grep('/^VALUE=/i', \array_slice($parts, 1)) ?: [];
            if (\count($values) > 1) {
                $issues[] = \sprintf('line %d repeats the VALUE parameter, kept the first one', $number);
                $first = array_key_first($values);
                $head = implode(';', array_filter($parts, static fn (string $part, int $index): bool => 0 === $index || $index === $first || !\in_array($index, array_keys($values), true), \ARRAY_FILTER_USE_BOTH));
                $line = $head.substr($line, $colon);
            }

            // sabre/vobject workaround: keep the raw value, as sabre loses information once it
            // has parsed it. It cannot tell an escaped comma from a list separator in N and ADR,
            // unescapes the values of unknown properties (jCard keeps them raw: RFC 7095,
            // section 5), and joins the parts of quoted-printable ones with commas.
            $value = substr($line, \strlen($head) + 1);
            if (1 === preg_match('/;ENCODING=QUOTED-PRINTABLE/i', $head)) {
                $value = quoted_printable_decode($value);
                if (1 === preg_match('/;CHARSET=([^;:]+)/i', $head, $charset) && 'UTF-8' !== strtoupper($charset[1])) {
                    $value = mb_convert_encoding($value, 'UTF-8', $charset[1]) ?: $value;
                }
            }

            $raw[$name][] = $value;

            $kept[] = $line;
        }

        try {
            $vCard = Reader::read(implode("\r\n", $kept)."\r\n", Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (ParseException|\TypeError $e) {
            // sabre/vobject workaround: sabre 5.0 throws TypeErrors, not only
            // ParseExceptions, on some malformed input.
            return new ParsedCard(null, $raw, [...$issues, 'not a valid vCard: '.$e->getMessage()]);
        }

        return new ParsedCard($vCard instanceof VCard ? $vCard : null, $raw, $issues);
    }

    /**
     * Unfolds the input (RFC 6350, section 3.2), including vCard 2.1 quoted-printable soft
     * line breaks.
     *
     * @return array<int, string> Logical lines, by the number of their first physical line
     */
    private static function logicalLines(string $text): array
    {
        $physical = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = [];
        $current = null;
        foreach ($physical as $index => $line) {
            $continuesQuotedPrintable = null !== $current && str_ends_with($lines[$current], '=') && 1 === preg_match('/;ENCODING=QUOTED-PRINTABLE[;:]/i', $lines[$current]);
            if (null !== $current && ('' !== $line && (' ' === $line[0] || "\t" === $line[0]))) {
                $lines[$current] .= substr($line, 1);
            } elseif ($continuesQuotedPrintable) {
                $lines[$current] .= "\r\n".$line;
            } else {
                $current = $index + 1;
                $lines[$current] = $line;
            }
        }

        return $lines;
    }

    /**
     * The position of the colon that ends the name and parameters, skipping quoted
     * parameter values.
     */
    private static function valueSeparator(string $line): ?int
    {
        $quoted = false;
        $length = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            if ('"' === $line[$i]) {
                $quoted = !$quoted;
            } elseif (':' === $line[$i] && !$quoted) {
                return $i;
            }
        }

        return null;
    }
}
