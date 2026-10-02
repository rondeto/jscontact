<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\VCard\VCardVersion;

/**
 * GEO and TZ values (RFC 9555, sections 2.8.1 and 2.8.2).
 *
 * @internal
 */
final class Geography
{
    private function __construct()
    {
    }

    /**
     * A "geo:" URI from a GEO value: a URI in vCard 4.0, a "latitude;longitude" pair of
     * floats in vCard 3.0 and 2.1.
     */
    public static function coordinates(string $value): ?string
    {
        $value = trim($value);
        if (0 === stripos($value, 'geo:')) {
            return $value;
        }

        if (1 === preg_match('/^(-?\d+(?:\.\d+)?)\s*[;,]\s*(-?\d+(?:\.\d+)?)$/', $value, $matches)) {
            return 'geo:'.$matches[1].','.$matches[2];
        }

        return null;
    }

    /**
     * An IANA time zone name from a TZ value: the name itself, or a UTC offset with whole
     * hours between -12 and +14, as "Etc/UTC" or "Etc/GMT" with the sign reversed.
     */
    public static function timeZone(string $value): ?string
    {
        $value = trim($value);
        if (1 === preg_match('/^([+-])(\d{2}):?(\d{2})$/', $value, $matches)) {
            $hours = (int) $matches[2] * ('-' === $matches[1] ? -1 : 1);
            if ('00' !== $matches[3] || $hours < -12 || $hours > 14) {
                return null;
            }

            return 0 === $hours ? 'Etc/UTC' : 'Etc/GMT'.($hours > 0 ? '-' : '+').abs($hours);
        }

        return 1 === preg_match('~^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+)*$~', $value) ? $value : null;
    }

    /**
     * The GEO value to write: the URI in vCard 4.0, a "latitude;longitude" pair in vCard 3.0
     * when the URI is that simple.
     */
    public static function formatCoordinates(string $coordinates, VCardVersion $version): ?string
    {
        if (VCardVersion::V40 === $version) {
            return $coordinates;
        }

        return 1 === preg_match('/^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/i', $coordinates, $matches) ? $matches[1].';'.$matches[2] : null;
    }

    /**
     * The UTC offset of an "Etc/UTC" or "Etc/GMT" time zone, for vCard 3.0, whose TZ is an
     * offset by default.
     */
    public static function utcOffset(string $timeZone): ?string
    {
        if ('Etc/UTC' === $timeZone) {
            return '+00:00';
        }

        if (1 === preg_match('~^Etc/GMT([+-])(\d{1,2})$~', $timeZone, $matches)) {
            return ('+' === $matches[1] ? '-' : '+').\sprintf('%02d', (int) $matches[2]).':00';
        }

        return null;
    }
}
