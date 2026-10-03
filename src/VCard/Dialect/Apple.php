<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Dialect;

use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\VCard\Dialect\Internal\VCardEdit;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;

/**
 * The vCard properties Apple's Address Book introduced (the "AB" of X-ABLabel), written by
 * Apple Contacts and iCloud, and by other address books for compatibility, such as Google
 * Contacts.
 *
 * Reading rewrites:
 * - X-ABRELATEDNAMES as RELATED, the relation coming from its label (_$!<Mother>!$_ is a parent);
 * - X-ABDATE labeled _$!<Anniversary>!$_ as ANNIVERSARY; other dates have no JSContact kind;
 * - dates of year 1604, Apple's "no year", as dates without year;
 * - X-ABADR as the CC parameter of the ADR of its group;
 * - X-SOCIALPROFILE as SOCIALPROFILE, X-SERVICE-TYPE as SERVICE-TYPE;
 * - X-ABShowAs:COMPANY, X-ADDRESSBOOKSERVER-KIND and X-ADDRESSBOOKSERVER-MEMBER as KIND and MEMBER;
 * - the built-in labels, such as _$!<HomePage>!$_, as readable ones ("home page").
 *
 * Writing does the reverse. The labels of relations and dates are kept verbatim: no
 * JSContact property holds them.
 */
final readonly class Apple implements Dialect
{
    /** Apple's built-in labels, and the readable labels they convert to. */
    private const array LABELS = [
        '_$!<Home>!$_' => 'home', '_$!<Work>!$_' => 'work', '_$!<Other>!$_' => 'other', '_$!<School>!$_' => 'school',
        '_$!<Main>!$_' => 'main', '_$!<Mobile>!$_' => 'mobile', '_$!<iPhone>!$_' => 'iPhone', '_$!<Pager>!$_' => 'pager',
        '_$!<HomeFAX>!$_' => 'home fax', '_$!<WorkFAX>!$_' => 'work fax', '_$!<OtherFAX>!$_' => 'other fax',
        '_$!<HomePage>!$_' => 'home page', '_$!<Anniversary>!$_' => 'anniversary',
        '_$!<Mother>!$_' => 'mother', '_$!<Father>!$_' => 'father', '_$!<Parent>!$_' => 'parent',
        '_$!<Brother>!$_' => 'brother', '_$!<Sister>!$_' => 'sister', '_$!<Child>!$_' => 'child',
        '_$!<Friend>!$_' => 'friend', '_$!<Spouse>!$_' => 'spouse', '_$!<Partner>!$_' => 'partner',
        '_$!<Assistant>!$_' => 'assistant', '_$!<Manager>!$_' => 'manager',
    ];

    /** Relation labels and the RELATED types (RFC 6350, section 6.6.6) they mean. */
    private const array RELATIONS = [
        'mother' => 'parent', 'father' => 'parent', 'parent' => 'parent',
        'brother' => 'sibling', 'sister' => 'sibling', 'child' => 'child',
        'friend' => 'friend', 'spouse' => 'spouse', 'assistant' => 'agent',
    ];

    /** RELATED types and the labels they convert to; other types are their own label. */
    private const array RELATION_LABELS = ['agent' => 'assistant'];

    /** The year Apple gives dates without a year. */
    private const string NO_YEAR = '1604';

    public function read(VCard $vCard): array
    {
        $issues = [];
        $labels = $this->labels($vCard);
        foreach (VCardEdit::properties($vCard) as $property) {
            $label = null === $property->group ? null : ($labels[strtolower($property->group)] ?? null);
            $label = null === $label ? null : (self::LABELS[(string) $label] ?? (string) $label);
            match ($property->name) {
                'X-ABRELATEDNAMES' => $this->readRelation($vCard, $property, $label),
                'X-ABDATE' => 'anniversary' === $label ? VCardEdit::replace($vCard, $property, 'ANNIVERSARY', $this->readDate($property), VCardEdit::parameters($property, ['X-APPLE-OMIT-YEAR'])) : null,
                'BDAY' => VCardEdit::replace($vCard, $property, 'BDAY', $this->readDate($property), VCardEdit::parameters($property, ['X-APPLE-OMIT-YEAR'])),
                'X-ABADR' => $this->readCountryCode($vCard, $property, $issues),
                'X-SOCIALPROFILE' => $this->readSocialProfile($vCard, $property),
                'IMPP' => $this->rename($property, 'X-SERVICE-TYPE', 'SERVICE-TYPE'),
                'X-ABSHOWAS' => 'COMPANY' === strtoupper((string) $property) && !isset($vCard->KIND) ? VCardEdit::replace($vCard, $property, 'KIND', 'org', []) : null,
                'X-ADDRESSBOOKSERVER-KIND' => isset($vCard->KIND) ? null : VCardEdit::replace($vCard, $property, 'KIND', strtolower((string) $property), []),
                'X-ADDRESSBOOKSERVER-MEMBER' => VCardEdit::replace($vCard, $property, 'MEMBER', (string) $property, VCardEdit::parameters($property)),
                default => null,
            };
        }

        foreach ($this->labels($vCard) as $label) {
            $value = (string) $label;
            if (isset(self::LABELS[$value])) {
                $label->setValue(self::LABELS[$value]);
            }
        }

        return $issues;
    }

    public function write(VCard $vCard): array
    {
        $issues = [];
        foreach (VCardEdit::properties($vCard) as $property) {
            match ($property->name) {
                'RELATED' => $this->writeRelation($vCard, $property),
                'ANNIVERSARY' => $this->writeLabeled($vCard, VCardEdit::replace($vCard, $property, 'X-ABDATE', ...$this->writeDate($property)), 'anniversary'),
                'BDAY' => VCardEdit::replace($vCard, $property, 'BDAY', ...$this->writeDate($property)),
                'ADR' => $this->writeCountryCode($vCard, $property),
                'SOCIALPROFILE' => $this->writeSocialProfile($vCard, $property),
                'IMPP' => $this->rename($property, 'SERVICE-TYPE', 'X-SERVICE-TYPE'),
                'KIND' => $this->writeKind($vCard, $property, $issues),
                'MEMBER' => VCardEdit::replace($vCard, $property, 'X-ADDRESSBOOKSERVER-MEMBER', (string) $property, VCardEdit::parameters($property)),
                'JSPROP' => $this->writeDatesWithoutYear($vCard, $property),
                default => null,
            };
        }

        $labels = array_flip(self::LABELS);
        foreach ($this->labels($vCard) as $label) {
            $value = (string) $label;
            if (isset($labels[$value])) {
                $label->setValue($labels[$value]);
            }
        }

        return $issues;
    }

    /**
     * X-ABRELATEDNAMES is a related person's name; its label says how they relate.
     */
    private function readRelation(VCard $vCard, Property $property, ?string $label): void
    {
        $params = VCardEdit::parameters($property, ['TYPE']);
        $types = [];
        foreach ($this->types($property) as $type) {
            if ('pref' === $type) {
                $params['PREF'] = ['1'];
            } else {
                $types[] = $type;
            }
        }

        $type = null === $label ? null : $this->relation($label);
        if (null !== $type) {
            $types[] = $type;
        }

        $params['VALUE'] = ['text'];
        if ([] !== $types) {
            $params['TYPE'] = array_values(array_unique($types));
        }

        VCardEdit::replace($vCard, $property, 'RELATED', (string) $property, $params);
    }

    /**
     * A related entity, as a name labeled with its first relation type. Apple keeps the
     * other types in TYPE, which it ignores.
     *
     * The label of its group, kept from reading, stays unless it names a relation the
     * entity no longer has: _$!<Mother>!$_ stays for a parent, _$!<Spouse>!$_ goes for a
     * friend, and a label of the user's own, such as "Godmother", always stays.
     */
    private function writeRelation(VCard $vCard, Property $property): void
    {
        $params = VCardEdit::parameters($property, ['VALUE', 'PREF']);
        $types = $this->types($property);
        if ([] !== VCardEdit::parts($property, 'PREF')) {
            $params['TYPE'] = [...$types, 'pref'];
        }

        $type = $types[0] ?? 'other';
        $label = self::RELATION_LABELS[$type] ?? $type;
        $property = VCardEdit::replace($vCard, $property, 'X-ABRELATEDNAMES', (string) $property, $params);
        $kept = null === $property->group ? null : ($this->labels($vCard)[strtolower($property->group)] ?? null);
        $relation = null === $kept ? null : $this->relation((string) $kept);
        if (null !== $kept && null !== $relation && !\in_array($relation, $types, true)) {
            $kept->setValue($label);
        }

        $this->writeLabeled($vCard, $property, $label);
    }

    /**
     * The RELATED type a label means, if any: a built-in or readable label of a relation
     * (_$!<Mother>!$_ or "mother" is a parent), or the name of a RELATED type.
     */
    private function relation(string $label): ?string
    {
        $label = self::LABELS[$label] ?? $label;

        return self::RELATIONS[$label] ?? (\in_array($label, Registry::RELATION_TYPES, true) ? $label : null);
    }

    /**
     * The value of a date, without the year Apple gives dates without one.
     */
    private function readDate(Property $property): string
    {
        $value = trim((string) $property);
        $noYear = VCardEdit::parts($property, 'X-APPLE-OMIT-YEAR')[0] ?? self::NO_YEAR;
        if (1 === preg_match('/^'.preg_quote($noYear, '/').'-?(\d{2})-?(\d{2})$/', $value, $matches)) {
            return '--'.$matches[1].$matches[2];
        }

        return $value;
    }

    /**
     * A date as Apple writes it: in extended format, and with year 1604 when it has none.
     *
     * @return array{string, array<string, list<string>>} The value and the parameters
     */
    private function writeDate(Property $property): array
    {
        $value = trim((string) $property);
        $params = VCardEdit::parameters($property);
        if (1 === preg_match('/^--(\d{2})-?(\d{2})$/', $value, $matches)) {
            return [self::NO_YEAR.'-'.$matches[1].'-'.$matches[2], [...$params, 'X-APPLE-OMIT-YEAR' => [self::NO_YEAR]]];
        }

        if (1 === preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return [$matches[1].'-'.$matches[2].'-'.$matches[3], $params];
        }

        return [$value, $params];
    }

    /**
     * vCard 3.0 has no dates without a year: the conversion writes such birthdays and
     * wedding anniversaries as JSPROP, which Apple writes in year 1604 instead.
     */
    private function writeDatesWithoutYear(VCard $vCard, Property $jsProp): void
    {
        $dates = VCardEdit::datesWithoutYear($jsProp);
        if (null === $dates) {
            return;
        }

        $vCard->remove($jsProp);
        foreach ($dates as $key => [$kind, $month, $day]) {
            $date = \sprintf('%s-%02d-%02d', self::NO_YEAR, $month, $day);
            $params = ['PROP-ID' => $key, 'X-APPLE-OMIT-YEAR' => self::NO_YEAR];
            if ('birth' === $kind) {
                $vCard->add('BDAY', $date, $params);
            } else {
                $property = $vCard->createProperty('X-ABDATE', $date, $params);
                $vCard->add($property);
                $this->writeLabeled($vCard, $property, 'anniversary');
            }
        }
    }

    /**
     * X-ABADR is the country code of the address of its group.
     *
     * @param list<Issue> $issues
     */
    private function readCountryCode(VCard $vCard, Property $property, array &$issues): void
    {
        $addresses = array_filter(VCardEdit::properties($vCard), static fn (Property $other): bool => 'ADR' === $other->name && null !== $property->group && 0 === strcasecmp((string) $other->group, $property->group));
        $address = 1 === \count($addresses) ? array_values($addresses)[0] : null;
        if (null === $address || [] !== VCardEdit::parts($address, 'CC')) {
            $issues[] = new Issue('', \sprintf('X-ABADR "%s" relates to no single address, kept it verbatim', (string) $property));

            return;
        }

        $address->add('CC', trim((string) $property));
        $vCard->remove($property);
    }

    private function writeCountryCode(VCard $vCard, Property $address): void
    {
        $countryCode = VCardEdit::parts($address, 'CC')[0] ?? null;
        if (null === $countryCode) {
            return;
        }

        unset($address['CC']);
        $address->group ??= $this->newGroup($vCard);
        $vCard->add($address->group.'.X-ABADR', $countryCode);
    }

    /**
     * X-SOCIALPROFILE gives the service as a TYPE, the user name as X-USER, and a user name
     * alone as its value.
     */
    private function readSocialProfile(VCard $vCard, Property $property): void
    {
        $params = VCardEdit::parameters($property, ['TYPE', 'X-USER', 'CHARSET']);
        $contexts = [];
        $types = VCardEdit::parts($property, 'TYPE');
        foreach ($types as $type) {
            if (\in_array(strtolower($type), ['home', 'work', 'pref'], true) || isset($params['SERVICE-TYPE'])) {
                $contexts[] = $type;
            } else {
                $params['SERVICE-TYPE'] = [$type];
            }
        }

        if ([] !== $contexts) {
            $params['TYPE'] = $contexts;
        }

        $value = (string) $property;
        $user = VCardEdit::parts($property, 'X-USER');
        if ([] !== $user) {
            $params['USERNAME'] = $user;
        }

        if (!str_contains($value, ':')) {
            $params['VALUE'] = ['text'];
        }

        VCardEdit::replace($vCard, $property, 'SOCIALPROFILE', $value, $params);
    }

    private function writeSocialProfile(VCard $vCard, Property $property): void
    {
        $params = VCardEdit::parameters($property, ['SERVICE-TYPE', 'USERNAME', 'VALUE']);
        $service = VCardEdit::parts($property, 'SERVICE-TYPE');
        if ([] !== $service) {
            $params['TYPE'] = [...$service, ...VCardEdit::parts($property, 'TYPE')];
        }

        $user = VCardEdit::parts($property, 'USERNAME');
        if ([] !== $user) {
            $params['X-USER'] = $user;
        }

        VCardEdit::replace($vCard, $property, 'X-SOCIALPROFILE', (string) $property, $params);
    }

    /**
     * @param list<Issue> $issues
     */
    private function writeKind(VCard $vCard, Property $property, array &$issues): void
    {
        $kind = strtolower((string) $property);
        if ('org' === $kind) {
            VCardEdit::replace($vCard, $property, 'X-ABSHOWAS', 'COMPANY', []);
        } elseif ('group' === $kind) {
            VCardEdit::replace($vCard, $property, 'X-ADDRESSBOOKSERVER-KIND', 'group', []);
        } elseif ('individual' !== $kind) {
            $issues[] = new Issue('/kind', \sprintf('Apple has no kind "%s", kept KIND', $kind));
        }
    }

    /**
     * Gives a property a label, unless its group has one.
     */
    private function writeLabeled(VCard $vCard, Property $property, string $label): void
    {
        if (null !== $property->group && isset($this->labels($vCard)[strtolower($property->group)])) {
            return;
        }

        $property->group ??= $this->newGroup($vCard);
        $vCard->add($property->group.'.X-ABLABEL', $label);
    }

    private function rename(Property $property, string $from, string $to): void
    {
        $values = VCardEdit::parts($property, $from);
        if ([] !== $values && [] === VCardEdit::parts($property, $to)) {
            unset($property[$from]);
            $property->add($to, $values);
        }
    }

    /**
     * @return list<string> The lowercase TYPE values
     */
    private function types(Property $property): array
    {
        return array_map(strtolower(...), VCardEdit::parts($property, 'TYPE'));
    }

    /**
     * The X-ABLabel of each group, by lowercase group name.
     *
     * @return array<string, Property>
     */
    private function labels(VCard $vCard): array
    {
        $labels = [];
        foreach (VCardEdit::properties($vCard) as $property) {
            if ('X-ABLABEL' === $property->name && null !== $property->group) {
                $labels[strtolower($property->group)] = $property;
            }
        }

        return $labels;
    }

    private function newGroup(VCard $vCard): string
    {
        $groups = [];
        foreach (VCardEdit::properties($vCard) as $property) {
            if (null !== $property->group) {
                $groups[strtolower($property->group)] = true;
            }
        }

        $i = 1;
        while (isset($groups['item'.$i])) {
            ++$i;
        }

        return 'item'.$i;
    }
}
