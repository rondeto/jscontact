<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

/**
 * vCard TIMESTAMP values (RFC 6350, section 4.3.5), read leniently.
 *
 * @internal
 */
final class Timestamp
{
    private function __construct()
    {
    }

    /**
     * Reads basic ("19951031T222710Z") and extended ("1995-10-31T22:27:10Z") formats, with
     * a "Z" or numeric UTC offset. Values without a time or an offset are not timestamps.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $pattern = '/^(\d{4})-?(\d{2})-?(\d{2})T(\d{2}):?(\d{2}):?(\d{2})(?:[.,](\d+))?(Z|[+-]\d{2}(?::?\d{2})?)$/i';
        if (1 !== preg_match($pattern, trim($value), $m)) {
            return null;
        }

        $offset = strtoupper($m[8]);
        if ('Z' === $offset) {
            $offset = '+00:00';
        } elseif (3 === \strlen($offset)) {
            $offset .= ':00';
        } elseif (5 === \strlen($offset)) {
            $offset = substr($offset, 0, 3).':'.substr($offset, 3);
        }

        $fraction = '' === $m[7] ? '000000' : substr(str_pad($m[7], 6, '0'), 0, 6);

        $dateTime = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            \sprintf('%s-%s-%sT%s:%s:%s.%s%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $fraction, $offset),
        );
        if (false === $dateTime || false !== \DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Writes the basic format of RFC 6350, in UTC. Fractions of seconds are dropped, as
     * vCard timestamps have none.
     */
    public static function format(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
