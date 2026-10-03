<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect;

use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\VCard\Dialect\Internal\VCardEdit;
use Rondeto\JSContact\VCard\Internal\RawProperty;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;

/**
 * The vCards of the Android contacts app, which writes what vCard 3.0 has no property for as
 * X-ANDROID-CUSTOM: a row of the Android contacts database, its type then its columns.
 *
 * Reading rewrites:
 * - relations as RELATED: "vnd.android.cursor.item/relation;Jane;14" is a spouse;
 * - wedding anniversaries (event type 1) as ANNIVERSARY;
 * - default nicknames (nickname type 1) as NICKNAME.
 *
 * The Android type of a relation is kept in X-ANDROID-TYPE, and the label of a custom one in
 * X-ANDROID-LABEL, so that a mother does not come back as a parent. Other events and
 * nicknames have no JSContact equivalent: they are kept verbatim.
 *
 * Writing does the reverse, and also writes the birthdays and wedding anniversaries
 * without a year that vCard 3.0 cannot hold, as Android does.
 */
final readonly class Android implements Dialect
{
    private const string RELATION = 'vnd.android.cursor.item/relation';

    private const string EVENT = 'vnd.android.cursor.item/contact_event';

    private const string NICKNAME = 'vnd.android.cursor.item/nickname';

    /** Android relation types and the RELATED types (RFC 6350, section 6.6.6) they mean. */
    private const array RELATIONS = [
        1 => 'agent', 2 => 'sibling', 3 => 'child', 5 => 'parent', 6 => 'friend',
        8 => 'parent', 9 => 'parent', 12 => 'kin', 13 => 'sibling', 14 => 'spouse',
    ];

    /** RELATED types and the Android relation type written for them. */
    private const array RELATION_TYPES = ['agent' => 1, 'child' => 3, 'friend' => 6, 'parent' => 9, 'kin' => 12, 'spouse' => 14];

    /** The type of a custom row, which its label names. */
    private const int CUSTOM = 0;

    /** The type of wedding anniversaries and default nicknames. */
    private const int DEFAULT = 1;

    public function read(VCard $vCard): array
    {
        foreach (VCardEdit::properties($vCard) as $property) {
            if ('X-ANDROID-CUSTOM' !== $property->name) {
                continue;
            }

            $row = $this->row($property);
            $type = $row[2] ?? '';
            $params = VCardEdit::parameters($property, ['CHARSET', 'ENCODING']);
            match (true) {
                self::RELATION === $row[0] && '' !== ($row[1] ?? '') => $this->readRelation($vCard, $property, $row[1], $type, $row[3] ?? '', $params),
                self::EVENT === $row[0] && (string) self::DEFAULT === $type && '' !== ($row[1] ?? '') => VCardEdit::replace($vCard, $property, 'ANNIVERSARY', $row[1], $params),
                self::NICKNAME === $row[0] && (string) self::DEFAULT === $type && '' !== ($row[1] ?? '') => VCardEdit::replace($vCard, $property, 'NICKNAME', $row[1], $params),
                default => null,
            };
        }

        return [];
    }

    public function write(VCard $vCard): array
    {
        foreach (VCardEdit::properties($vCard) as $property) {
            match ($property->name) {
                'RELATED' => $this->writeRelation($vCard, $property),
                'ANNIVERSARY' => $this->writeRow($vCard, $property, [self::EVENT, $this->date((string) $property), (string) self::DEFAULT], VCardEdit::parameters($property, ['VALUE'])),
                'JSPROP' => $this->writeDatesWithoutYear($vCard, $property),
                default => null,
            };
        }

        return [];
    }

    /**
     * @param array<string, list<string>> $params
     */
    private function readRelation(VCard $vCard, Property $property, string $name, string $type, string $label, array $params): void
    {
        $relation = ctype_digit($type) ? (self::RELATIONS[(int) $type] ?? null) : null;
        if ((string) self::CUSTOM === $type && \in_array(strtolower($label), Registry::RELATION_TYPES, true)) {
            $relation = strtolower($label);
        }

        $params['VALUE'] = ['text'];
        if (null !== $relation) {
            $params['TYPE'] = [$relation];
        }

        if ('' !== $type) {
            $params['X-ANDROID-TYPE'] = [$type];
        }

        if ('' !== $label) {
            $params['X-ANDROID-LABEL'] = [$label];
        }

        VCardEdit::replace($vCard, $property, 'RELATED', $name, $params);
    }

    /**
     * A related entity, as a relation row: of the type it was read with, or of the type of
     * its first RELATED type, or a custom one named after it.
     */
    private function writeRelation(VCard $vCard, Property $property): void
    {
        $types = array_map(strtolower(...), VCardEdit::parts($property, 'TYPE'));
        $type = VCardEdit::parts($property, 'X-ANDROID-TYPE')[0] ?? null;
        $label = VCardEdit::parts($property, 'X-ANDROID-LABEL')[0] ?? null;
        if (null === $type) {
            $type = (string) (self::RELATION_TYPES[$types[0] ?? ''] ?? self::CUSTOM);
            $label = (string) self::CUSTOM === $type ? ($types[0] ?? '') : '';
        }

        $params = VCardEdit::parameters($property, ['VALUE', 'TYPE', 'X-ANDROID-TYPE', 'X-ANDROID-LABEL']);
        $this->writeRow($vCard, $property, [self::RELATION, (string) $property, $type, $label ?? ''], $params);
    }

    /**
     * vCard 3.0 has no dates without a year: the conversion writes them as JSPROP. Android
     * writes them as --MM-DD.
     */
    private function writeDatesWithoutYear(VCard $vCard, Property $jsProp): void
    {
        $dates = VCardEdit::datesWithoutYear($jsProp);
        if (null === $dates) {
            return;
        }

        $vCard->remove($jsProp);
        foreach ($dates as $key => [$kind, $month, $day]) {
            $date = \sprintf('--%02d-%02d', $month, $day);
            if ('birth' === $kind) {
                $vCard->add('BDAY', $date, ['PROP-ID' => $key]);
            } else {
                $this->writeRow($vCard, null, [self::EVENT, $date, (string) self::DEFAULT], ['PROP-ID' => [$key]]);
            }
        }
    }

    /**
     * A date as Android writes it: YYYY-MM-DD, or --MM-DD without a year.
     */
    private function date(string $value): string
    {
        $value = trim($value);
        if (1 === preg_match('/^(\d{4}|-)-?(\d{2})-?(\d{2})$/', $value, $matches)) {
            return $matches[1].'-'.$matches[2].'-'.$matches[3];
        }

        return $value;
    }

    /**
     * Replaces a property, if any, with an X-ANDROID-CUSTOM row, in the same group. Android
     * writes rows of 16 columns.
     *
     * @param list<string>                $row
     * @param array<string, list<string>> $params
     */
    private function writeRow(VCard $vCard, ?Property $replaced, array $row, array $params): void
    {
        if (null !== $replaced) {
            $vCard->remove($replaced);
        }

        $row = array_pad($row, 16, '');
        // sabre/vobject would escape the semicolons between the columns: write them as is.
        $property = new RawProperty($vCard, 'X-ANDROID-CUSTOM', null, $params, $replaced?->group);
        $property->setRawMimeDirValue(implode(';', array_map(static fn (string $column): string => strtr($column, ['\\' => '\\\\', ';' => '\;', "\n" => '\n']), $row)));

        $vCard->add($property);
    }

    /**
     * The columns of a row: its type, then the data columns.
     *
     * @return non-empty-list<string>
     */
    private function row(Property $property): array
    {
        // sabre/vobject splits quoted-printable values on semicolons, but not the others.
        $parts = [];
        foreach ($property->getParts() as $part) {
            $parts[] = \is_scalar($part) ? (string) $part : '';
        }

        if ([] === $parts) {
            return [''];
        }

        return 1 === \count($parts) ? explode(';', $parts[0]) : $parts;
    }
}
