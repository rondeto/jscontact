<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Timestamp as TimestampValue;
use Rondeto\JSContact\VCard\VCardVersion;

/**
 * Anniversary dates: vCard DATE and TIMESTAMP values, and the PartialDate and Timestamp
 * objects they convert to (RFC 9555, section 2.2.2).
 *
 * @internal
 */
final class Dates
{
    private function __construct()
    {
    }

    /**
     * A whole or partial date, or a UTC date-time. Local times, times alone, and dates that
     * only have a month or a day do not convert (RFC 9555, section 2.2.2).
     */
    public static function parse(string $value): PartialDate|TimestampValue|null
    {
        $value = trim($value);
        if (str_contains($value, 'T')) {
            $isUtc = str_ends_with(strtoupper($value), 'Z');
            $utc = $isUtc ? Timestamp::parse($value) : null;

            return null === $utc ? null : new TimestampValue($utc);
        }

        $patterns = [
            '/^(?<year>\d{4})-?(?<month>\d{2})-?(?<day>\d{2})$/', // 19850412, 1985-04-12
            '/^(?<year>\d{4})-(?<month>\d{2})$/',                 // 1985-04
            '/^(?<year>\d{4})$/',                                 // 1985
            '/^--(?<month>\d{2})-?(?<day>\d{2})$/',               // --0412, --04-12
        ];
        foreach ($patterns as $pattern) {
            if (1 !== preg_match($pattern, $value, $matches)) {
                continue;
            }

            $year = isset($matches['year']) ? (int) $matches['year'] : null;
            $month = isset($matches['month']) ? (int) $matches['month'] : null;
            $day = isset($matches['day']) ? (int) $matches['day'] : null;
            if (null !== $month && ($month < 1 || $month > 12)) {
                return null;
            }

            if (null !== $day && null !== $month && !checkdate($month, $day, $year ?? 2000)) {
                return null;
            }

            return new PartialDate($year, $month, $day);
        }

        return null;
    }

    /**
     * The vCard value of a date: basic format in vCard 4.0 (RFC 6350, section 4.3), extended
     * in vCard 3.0, which has no partial dates.
     *
     * @return string|null null for a partial date in vCard 3.0
     */
    public static function format(PartialDate|TimestampValue $date, VCardVersion $version): ?string
    {
        $isV40 = VCardVersion::V40 === $version;
        if ($date instanceof TimestampValue) {
            return $date->utc->setTimezone(new \DateTimeZone('UTC'))->format($isV40 ? 'Ymd\THis\Z' : 'Y-m-d\TH:i:s\Z');
        }

        $year = null === $date->year ? null : \sprintf('%04d', $date->year);
        $month = null === $date->month ? null : \sprintf('%02d', $date->month);
        $day = null === $date->day ? null : \sprintf('%02d', $date->day);

        if (!\in_array(null, [$year, $month, $day], true)) {
            return $isV40 ? $year.$month.$day : $year.'-'.$month.'-'.$day;
        }

        if (!$isV40) {
            return null;
        }

        return match (true) {
            null !== $year && null !== $month => $year.'-'.$month,
            null !== $year => $year,
            null !== $month && null !== $day => '--'.$month.$day,
            default => null,
        };
    }
}
