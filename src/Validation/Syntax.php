<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

/**
 * Lexical rules shared by the JSON reader and the validation constraints.
 *
 * @internal
 */
final class Syntax
{
    /** RFC 9553, section 1.4.1. */
    public const string ID = '/^[A-Za-z0-9_-]{1,255}$/';

    /** RFC 9553, section 1.7.2. */
    private const string IANA_NAME = '/^[A-Za-z0-9@]+$/';

    /** The "v-extension" ABNF of RFC 9553, section 1.8.1. */
    private const string VENDOR_EXTENSION = '/^[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)*:[^\x00-\x08\x0A-\x1F\x7F"\/~]+$/u';

    /** A URI scheme followed by a colon (RFC 3986, section 3.1); the rest is not checked. */
    public const string URI = '/^[A-Za-z][A-Za-z0-9+.-]*:\S*$/';

    /**
     * Only checks there is exactly one "@" with something on both sides: full RFC 5322
     * addr-spec validation rejects too many addresses that mail servers accept.
     */
    public const string EMAIL_ADDRESS = '/^[^@\s]+@[^@\s]+$/';

    /** RFC 9553, section 1.4.5. */
    private const string UTC_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d*[1-9])?Z$/';

    private function __construct()
    {
    }

    public static function isId(string $value): bool
    {
        return 1 === preg_match(self::ID, $value);
    }

    public static function isIanaName(string $value): bool
    {
        return 1 === preg_match(self::IANA_NAME, $value);
    }

    public static function isVendorExtension(string $value): bool
    {
        return 1 === preg_match(self::VENDOR_EXTENSION, $value);
    }

    public static function isUtcDateTime(string $value): bool
    {
        return 1 === preg_match(self::UTC_DATE_TIME, $value);
    }

    public static function formatUtcDateTime(\DateTimeImmutable $dateTime): string
    {
        $utc = $dateTime->setTimezone(new \DateTimeZone('UTC'));
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s').('' === $fraction ? '' : '.'.$fraction).'Z';
    }

    /**
     * Parses any RFC 3339 date-time, including ones that are not valid UTCDateTime
     * values (lowercase letters, time offsets, trailing zeros), to UTC.
     */
    public static function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}:\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $value, $matches)) {
            return null;
        }

        $fraction = '' === $matches[3] ? '' : substr(str_pad($matches[3], 7, '0'), 0, 7);
        $offset = 'Z' === strtoupper($matches[4]) ? '+00:00' : $matches[4];
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s'.('' === $fraction ? '' : '.u').'P', $matches[1].'T'.$matches[2].$fraction.$offset);

        if (false === $dateTime || false !== \DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * The registered value that $value only differs from by case, if any. Such values are
     * invalid (RFC 9553, section 1.7.1).
     *
     * @param list<string> $known
     */
    public static function caseVariantOf(string $value, array $known): ?string
    {
        foreach ($known as $candidate) {
            if ($candidate !== $value && 0 === strcasecmp($candidate, $value)) {
                return $candidate;
            }
        }

        return null;
    }
}
