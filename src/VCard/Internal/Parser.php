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
 * keeps one value of a repeated or listed VALUE parameter (sabre/vobject 5.0 crashes on
 * them), and keeps the raw value of each property: sabre cannot tell escaped commas in N
 * and ADR from list separators, and loses the escapes of unknown properties, which jCard
 * keeps as is.
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

            // sabre/vobject workaround: a VALUE parameter with several values, repeated
            // (VALUE=text;VALUE=TEXT) or listed (VALUE=uri,text), makes sabre 5.0 throw a
            // TypeError (Document::getClassNameForPropertyValue() gets an array). Fixed
            // upstream by sabre-io/vobject#795.
            $valueParts = array_filter(\array_slice($parts, 1, null, true), static fn (string $part): bool => 0 === stripos($part, 'VALUE='));
            $types = [];
            foreach ($valueParts as $part) {
                $type = substr($part, 6);
                array_push($types, ...(str_starts_with($type, '"') ? [$type] : explode(',', $type)));
            }

            $first = array_key_first($valueParts);
            if (null !== $first && \count($types) > 1) {
                $issues[] = \count($valueParts) > 1
                    ? \sprintf('line %d repeats the VALUE parameter, kept the first one', $number)
                    : \sprintf('line %d lists several values in the VALUE parameter, kept the first one', $number);
                $parts[$first] = substr($parts[$first], 0, 6).$types[0];
                $head = implode(';', array_diff_key($parts, \array_slice($valueParts, 1, null, true)));
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
