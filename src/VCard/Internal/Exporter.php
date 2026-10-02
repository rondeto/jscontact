<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Conversion\IssueCollector;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\VCard\VCardVersion;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Converts a Card to a vCard, by the reverse rules of RFC 9555 (section 3).
 *
 * @internal
 */
final class Exporter
{
    /** vCard 4.0 properties that vCard 3.0 does not define. */
    private const array NOT_IN_V30 = ['KIND', 'MEMBER', 'LANGUAGE', 'CREATED', 'SOCIALPROFILE', 'CONTACT-URI'];

    /** Phone features and the TEL TYPE values they convert to (RFC 9555, Table 3). */
    private const array PHONE_TYPES = [
        'mobile' => 'cell', 'fax' => 'fax', 'main-number' => 'main-number', 'pager' => 'pager',
        'text' => 'text', 'textphone' => 'textphone', 'video' => 'video', 'voice' => 'voice',
    ];

    /** Index of each AddressComponent kind in the ADR value of RFC 9554. */
    private const array ADDRESS_INDEXES = [
        'postOfficeBox' => 0, 'locality' => 3, 'region' => 4, 'postcode' => 5, 'country' => 6, 'room' => 7,
        'apartment' => 8, 'floor' => 9, 'number' => 10, 'name' => 11, 'building' => 12, 'block' => 13,
        'subdistrict' => 14, 'district' => 15, 'landmark' => 16, 'direction' => 17,
    ];

    /** Kinds that also fit in the ADR value of vCard 3.0, and their index there. */
    private const array LEGACY_ADDRESS_INDEXES = [
        'postOfficeBox' => 0, 'apartment' => 1, 'name' => 2, 'locality' => 3, 'region' => 4, 'postcode' => 5, 'country' => 6,
    ];

    private readonly IssueCollector $issues;

    private readonly VCard $vCard;

    /** @var array<string, true> Lowercase group names in use */
    private array $groups = [];

    /** @var array<string, true> Uppercase names of the properties kept verbatim */
    private array $verbatim = [];

    public function __construct(
        private readonly VCardVersion $version,
    ) {
        $this->issues = new IssueCollector();
        $this->vCard = new VCard(['VERSION' => $version->value]);
        // sabre/vobject adds a PRODID and a UID of its own.
        $this->vCard->remove('PRODID');
        $this->vCard->remove('UID');
    }

    /**
     * @return Result<VCard>
     */
    public function export(Card $card): Result
    {
        $this->collectGroups($card);

        $this->single('UID', $card->uid, '/uid');
        $this->single('PRODID', $card->prodId, '/prodId');
        $this->single('KIND', $card->kind, '/kind');
        $this->single('LANGUAGE', $card->language, '/language');
        $this->single('CREATED', null === $card->created ? null : Timestamp::format($card->created), '/created');
        $this->single('REV', null === $card->updated ? null : Timestamp::format($card->updated), '/updated');

        $this->name($card->name);

        foreach ($card->nicknames as $key => $nickname) {
            $this->nickname((string) $key, $nickname);
        }

        foreach ($card->emails as $key => $email) {
            $this->email((string) $key, $email);
        }

        foreach ($card->phones as $key => $phone) {
            $this->phone((string) $key, $phone);
        }

        foreach ($card->addresses as $key => $address) {
            $this->address((string) $key, $address);
        }

        foreach ($card->onlineServices as $key => $service) {
            $this->onlineService((string) $key, $service);
        }

        foreach ($card->links as $key => $link) {
            $this->link((string) $key, $link);
        }

        foreach ($card->notes as $key => $note) {
            $this->note((string) $key, $note);
        }

        foreach ($card->members as $member) {
            $this->add('MEMBER', $member, path: '/members');
        }

        if ([] !== $card->keywords) {
            $this->add('CATEGORIES', $card->keywords);
        }

        $this->verbatim($card->vCardProps);
        $this->unknown($card);

        return new Result($this->vCard, $this->issues->all());
    }

    private function single(string $name, ?string $value, string $path): void
    {
        if (null !== $value && !isset($this->verbatim[$name])) {
            $this->add($name, $value, path: $path);
        }
    }

    private function name(?Name $name): void
    {
        $params = null === $name ? [] : $name->vCardParams;
        $hasComponents = null !== $name && [] !== $name->components;

        if ($hasComponents || VCardVersion::V30 === $this->version) {
            $this->n($name, $params);
            $params = [];
        }

        if (isset($this->verbatim['FN'])) {
            return;
        }

        $group = \is_string($params['group'] ?? null) ? $params['group'] : null;
        if (null !== $name?->full) {
            $this->add('FN', $name->full, $this->parameters($params), $group);
        } elseif ($hasComponents) {
            // RFC 9555, section 3.1: derive the full name and say so.
            $this->add('FN', $this->fullName($name), ['DERIVED' => 'TRUE', ...$this->parameters($params)], $group);
        } else {
            $this->add('FN', '', $this->parameters($params), $group);
        }
    }

    /**
     * @param array<string, string|list<string>> $vCardParams
     */
    private function n(?Name $name, array $vCardParams): void
    {
        $isV40 = VCardVersion::V40 === $this->version;
        $byKind = array_fill_keys(Components::NAME_KINDS, []);
        $phoneticsByKind = array_fill_keys(Components::NAME_KINDS, []);
        $positions = [];
        foreach ($name->components ?? [] as $index => $component) {
            if ('separator' === $component->kind) {
                $positions[$index] = $component->value;
            } elseif (isset($byKind[$component->kind])) {
                $positions[$index] = [$component->kind, \count($byKind[$component->kind])];
                $byKind[$component->kind][] = $component->value;
                $phoneticsByKind[$component->kind][] = $component->phonetic ?? '';
            } else {
                $this->issues->add('/name/components/'.$index, \sprintf('vCard has no name component of kind "%s", left it out', $component->kind));
            }
        }

        $components = $this->nameLayout($byKind, $isV40);
        $phonetics = $this->nameLayout($phoneticsByKind, $isV40);
        $offsets = [
            'surname' => [0, 0], 'given' => [1, 0], 'given2' => [2, 0], 'title' => [3, 0],
            'credential' => [4, \count($byKind['generation'])],
            'surname2' => $isV40 ? [5, 0] : [0, \count($byKind['surname'])],
            'generation' => $isV40 ? [6, 0] : [4, 0],
        ];
        $positions = array_map(static fn (array|string $position): array|string => \is_string($position) ? $position : [$offsets[$position[0]][0], $offsets[$position[0]][1] + $position[1]], $positions);
        $count = \count($components);

        $params = $this->parameters($vCardParams);
        $group = \is_string($vCardParams['group'] ?? null) ? $vCardParams['group'] : null;
        if (null !== $name && $name->isOrdered && [] !== $name->components) {
            $params['JSCOMPS'] = Components::jsComps($name->defaultSeparator, array_values($positions));
        }

        if (null !== $name && [] !== $name->sortAs) {
            $sortAs = [];
            foreach (Components::NAME_KINDS as $index => $kind) {
                $sortAs[$index] = $name->sortAs[$kind] ?? '';
            }

            $params['SORT-AS'] = rtrim(implode(',', \array_slice($sortAs, 0, $count)), ',');
        }

        $hasPhonetics = null !== $name && (null !== $name->phoneticSystem || null !== $name->phoneticScript);
        if ($hasPhonetics) {
            $params['ALTID'] = \is_string($vCardParams['altid'] ?? null) ? $vCardParams['altid'] : '1';
        }

        $this->add('N', $this->structured($components), $params, $group);

        if ($hasPhonetics) {
            $phoneticParams = ['ALTID' => $params['ALTID'], 'PHONETIC' => $name->phoneticSystem ?? 'script'];
            if (null !== $name->phoneticScript) {
                $phoneticParams['SCRIPT'] = $name->phoneticScript;
            }

            // Components without any phonetic value are left empty.
            $phonetics = array_map(static fn (array $values): array => [] === array_filter($values, static fn (string $value): bool => '' !== $value) ? [] : $values, $phonetics);
            $this->add('N', $this->structured($phonetics), $phoneticParams);
        }
    }

    private function nickname(string $key, Nickname $nickname): void
    {
        $this->entry('NICKNAME', $nickname->name, $key, $nickname->contexts, $nickname->pref, null, $nickname->vCardParams, '/nicknames/'.$key);
    }

    private function email(string $key, EmailAddress $email): void
    {
        $this->entry('EMAIL', $email->address, $key, $email->contexts, $email->pref, $email->label, $email->vCardParams, '/emails/'.$key);
    }

    private function phone(string $key, Phone $phone): void
    {
        $types = [];
        foreach ($phone->features as $feature) {
            if (isset(self::PHONE_TYPES[$feature])) {
                $types[] = self::PHONE_TYPES[$feature];
            } else {
                $this->issues->add('/phones/'.$key.'/features/'.$feature, 'vCard has no TEL type for this feature, left it out');
            }
        }

        $params = [];
        if (VCardVersion::V40 === $this->version && 1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $phone->number)) {
            $params['VALUE'] = 'uri';
        }

        $this->entry('TEL', $phone->number, $key, $phone->contexts, $phone->pref, $phone->label, $phone->vCardParams, '/phones/'.$key, $params, $types);
    }

    private function address(string $key, Address $address): void
    {
        $path = '/addresses/'.$key;
        if (null !== $address->phoneticSystem || null !== $address->phoneticScript) {
            $this->issues->add($path, 'phonetic addresses are not converted to vCard yet, left the phonetics out');
        }

        $kinds = array_unique(array_map(static fn (\Rondeto\JSContact\Model\AddressComponent $component): string => $component->kind, $address->components));
        $isLegacy = VCardVersion::V30 === $this->version || [] === array_diff($kinds, ['separator', ...array_keys(self::LEGACY_ADDRESS_INDEXES)]);
        $components = array_fill(0, $isLegacy ? 7 : 18, []);
        $positions = [];
        $merged = false;

        foreach ($address->components as $index => $component) {
            if ('separator' === $component->kind) {
                $positions[] = $component->value;
                continue;
            }

            $designated = ($isLegacy ? self::LEGACY_ADDRESS_INDEXES : self::ADDRESS_INDEXES)[$component->kind] ?? null;
            if (null === $designated && $isLegacy && isset(self::ADDRESS_INDEXES[$component->kind])) {
                // vCard 3.0: combine the components RFC 9554 added in the extended or street address.
                $designated = \in_array($component->kind, Components::LEGACY_EXTENDED, true) ? 1 : 2;
                $merged = true;
                $this->issues->add($path.'/components/'.$index, \sprintf('vCard 3.0 has no "%s" address component, merged it in the %s address', $component->kind, 1 === $designated ? 'extended' : 'street'));
            }

            if (null === $designated) {
                $this->issues->add($path.'/components/'.$index, \sprintf('vCard has no address component of kind "%s", left it out', $component->kind));
                continue;
            }

            $positions[] = [$designated, \count($components[$designated])];
            $components[$designated][] = $component->value;
        }

        if ($merged) {
            // One value per legacy component, as older readers expect: "54321 Oak St".
            foreach ([1, 2] as $index) {
                $components[$index] = [] === $components[$index] ? [] : [implode(' ', $components[$index])];
            }
        }

        if (!$isLegacy) {
            // RFC 9554, section 2.1: also write the street and extended address for older readers.
            foreach ([1 => Components::LEGACY_EXTENDED, 2 => Components::LEGACY_STREET] as $index => $legacyKinds) {
                $values = [];
                foreach ($legacyKinds as $kind) {
                    array_push($values, ...$components[self::ADDRESS_INDEXES[$kind]]);
                }

                $components[$index] = [] === $values ? [] : [implode(' ', $values)];
            }
        }

        $params = [];
        if ($address->isOrdered && [] !== $address->components && !$merged) {
            $params['JSCOMPS'] = Components::jsComps($address->defaultSeparator, $positions);
        }

        foreach (['LABEL' => $address->full, 'GEO' => $address->coordinates, 'TZ' => $address->timeZone, 'CC' => $address->countryCode] as $name => $value) {
            if (null !== $value) {
                $params[$name] = $value;
            }
        }

        $this->entry('ADR', $this->structured($components), $key, $address->contexts, $address->pref, null, $address->vCardParams, $path, $params);
    }

    private function onlineService(string $key, OnlineService $service): void
    {
        $params = [];
        if (null !== $service->service) {
            $params['SERVICE-TYPE'] = $service->service;
        }

        if ('impp' === $service->vCardName && null !== $service->uri) {
            if (null !== $service->user) {
                $params['USERNAME'] = $service->user;
            }

            $this->entry('IMPP', $service->uri, $key, $service->contexts, $service->pref, $service->label, $service->vCardParams, '/onlineServices/'.$key, $params);

            return;
        }

        if (null !== $service->uri) {
            if (null !== $service->user) {
                $params['USERNAME'] = $service->user;
            }

            $value = $service->uri;
        } else {
            $params['VALUE'] = 'text';
            $value = $service->user ?? '';
        }

        $this->entry('SOCIALPROFILE', $value, $key, $service->contexts, $service->pref, $service->label, $service->vCardParams, '/onlineServices/'.$key, $params);
    }

    private function link(string $key, Link $link): void
    {
        $params = null === $link->mediaType ? [] : ['MEDIATYPE' => $link->mediaType];
        $name = Link::KIND_CONTACT === $link->kind ? 'CONTACT-URI' : 'URL';
        if (null !== $link->kind && Link::KIND_CONTACT !== $link->kind) {
            $this->issues->add('/links/'.$key.'/kind', \sprintf('vCard has no link of kind "%s", wrote a URL', $link->kind));
        }

        $this->entry($name, $link->uri, $key, $link->contexts, $link->pref, $link->label, $link->vCardParams, '/links/'.$key, $params);
    }

    private function note(string $key, Note $note): void
    {
        $params = [];
        if (null !== $note->created) {
            $params['CREATED'] = Timestamp::format($note->created);
        }

        if (null !== $note->author?->uri) {
            $params['AUTHOR'] = $note->author->uri;
        }

        if (null !== $note->author?->name) {
            $params['AUTHOR-NAME'] = $note->author->name;
        }

        $this->entry('NOTE', $note->note, $key, [], null, null, $note->vCardParams, '/notes/'.$key, $params);
    }

    /**
     * Adds a property converted from an entry of an Id map: PROP-ID, TYPE, PREF, group and
     * X-ABLabel, and the parameters kept in vCardParams.
     *
     * @param string|list<string|list<string>>   $value
     * @param list<string>                       $contexts
     * @param array<string, string|list<string>> $vCardParams
     * @param array<string, string>              $params      Property-specific parameters
     * @param list<string>                       $types       Property-specific TYPE values
     */
    private function entry(string $name, string|array $value, string $key, array $contexts, ?int $pref, ?string $label, array $vCardParams, string $path, array $params = [], array $types = []): void
    {
        foreach ($contexts as $context) {
            $type = match ($context) {
                'private' => 'home',
                'work', 'billing', 'delivery' => $context,
                default => null,
            };
            if (null === $type) {
                $this->issues->add($path.'/contexts/'.$context, 'vCard has no TYPE for this context, left it out');
            } else {
                $types[] = $type;
            }
        }

        if (null !== $pref && VCardVersion::V40 === $this->version) {
            $params['PREF'] = (string) $pref;
        } elseif (null !== $pref) {
            // vCard 3.0 only says whether a value is preferred.
            $types[] = 'pref';
            if (1 !== $pref) {
                $this->issues->add($path.'/pref', \sprintf('vCard 3.0 has no preference order, wrote preference %d as TYPE=pref', $pref));
            }
        }

        $kept = $this->parameters($vCardParams);
        foreach ((array) ($kept['TYPE'] ?? []) as $type) {
            $types[] = $type;
        }

        unset($kept['TYPE']);

        $params = [...$kept, ...$params, 'PROP-ID' => $key];
        if ([] !== $types) {
            $params['TYPE'] = array_values(array_unique($types));
        }

        $group = \is_string($vCardParams['group'] ?? null) ? $vCardParams['group'] : null;
        if (null !== $label) {
            $group ??= $this->newGroup();
        }

        $this->add($name, $value, $params, $group, $path);
        if (null !== $label) {
            $this->add('X-ABLABEL', $label, [], $group);
        }
    }

    /**
     * @param list<VCardProperty> $properties
     */
    private function verbatim(array $properties): void
    {
        foreach ($properties as $property) {
            if ('version' === $property->name) {
                continue;
            }

            $jCard = [$property->name, (object) $property->parameters, $property->type, ...$property->values];
            $document = Reader::readJson(json_encode(['vcard', [['version', new \stdClass(), 'text', '4.0'], $jCard]], \JSON_THROW_ON_ERROR));
            foreach ($document?->children() ?? [] as $child) {
                if (!$child instanceof Property || 'VERSION' === $child->name) {
                    continue;
                }

                if ('unknown' === $property->type && \is_string($property->values[0] ?? null) && 1 === \count($property->values)) {
                    // jCard keeps the raw value of unknown types (RFC 7095, section 5): no escaping.
                    $parameters = [];
                    foreach ($child->parameters() as $name => $parameter) {
                        if ('VALUE' !== $name) {
                            $parameters[(string) $name] = $parameter->getParts();
                        }
                    }

                    $raw = new RawProperty($this->vCard, $child->name ?? strtoupper($property->name), null, $parameters, $child->group);
                    $raw->setRawMimeDirValue($property->values[0]);
                    $this->vCard->add($raw);
                    continue;
                }

                $this->vCard->add(clone $child);
            }
        }
    }

    /**
     * Writes properties this library does not convert to vCard as JSPROP (RFC 9555,
     * section 3.1.1). Those inside arrays cannot be pointed at, so they are left out.
     */
    private function unknown(Card $card): void
    {
        foreach ($card->extra as $name => $value) {
            if (\in_array($name, Registry::UNMODELED_CARD_PROPERTIES, true)) {
                $this->issues->add('/'.$name, 'not converted to vCard properties yet, wrote it as JSPROP');
            }

            $this->jsProp((string) $name, $value);
        }

        $maps = [
            'nicknames' => $card->nicknames, 'emails' => $card->emails, 'phones' => $card->phones,
            'addresses' => $card->addresses, 'onlineServices' => $card->onlineServices,
            'links' => $card->links, 'notes' => $card->notes,
        ];
        foreach ($maps as $map => $entries) {
            foreach ($entries as $key => $entry) {
                foreach ($entry->extra as $name => $value) {
                    $this->jsProp($map.'/'.$this->escape((string) $key).'/'.$this->escape((string) $name), $value);
                }

                if ($entry instanceof Note && null !== $entry->author) {
                    foreach ($entry->author->extra as $name => $value) {
                        $this->jsProp('notes/'.$this->escape((string) $key).'/author/'.$this->escape((string) $name), $value);
                    }
                }

                if ($entry instanceof Address) {
                    $this->componentExtras('/addresses/'.$key, $entry->components);
                }
            }
        }

        if (null !== $card->name) {
            foreach ($card->name->extra as $name => $value) {
                $this->jsProp('name/'.$this->escape((string) $name), $value);
            }

            $this->componentExtras('/name', $card->name->components);
        }
    }

    /**
     * @param list<object{extra: array<array-key, mixed>}> $components
     */
    private function componentExtras(string $path, array $components): void
    {
        foreach ($components as $index => $component) {
            if ([] !== $component->extra) {
                $this->issues->add($path.'/components/'.$index, 'properties inside components cannot be written to vCard, left them out');
            }
        }
    }

    private function jsProp(string $pointer, mixed $value): void
    {
        $this->add('JSPROP', json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), ['JSPTR' => $pointer]);
    }

    /**
     * @param string|list<string|list<string>>   $value
     * @param array<string, string|list<string>> $params
     */
    private function add(string $name, string|array $value, array $params = [], ?string $group = null, string $path = ''): void
    {
        if (VCardVersion::V30 === $this->version && \in_array($name, self::NOT_IN_V30, true)) {
            $this->issues->add($path, \sprintf('vCard 3.0 does not define %s, wrote it anyway', $name));
        }

        $this->vCard->add((null === $group ? '' : $group.'.').$name, $value, $params);
    }

    /**
     * vCardParams in sabre/vobject form: uppercase names, without the group.
     *
     * @param array<string, string|list<string>> $vCardParams
     *
     * @return array<string, string|list<string>>
     */
    private function parameters(array $vCardParams): array
    {
        $params = [];
        foreach ($vCardParams as $name => $value) {
            if ('group' !== $name) {
                $params[strtoupper($name)] = $value;
            }
        }

        return $params;
    }

    private function collectGroups(Card $card): void
    {
        $objects = [$card->name, ...$card->nicknames, ...$card->emails, ...$card->phones, ...$card->addresses, ...$card->onlineServices, ...$card->links, ...$card->notes];
        foreach ($objects as $object) {
            $group = $object?->vCardParams['group'] ?? null;
            if (\is_string($group)) {
                $this->groups[strtolower($group)] = true;
            }
        }

        foreach ($card->vCardProps as $property) {
            $this->verbatim[strtoupper($property->name)] = true;
            if (\is_string($property->parameters['group'] ?? null)) {
                $this->groups[strtolower($property->parameters['group'])] = true;
            }
        }
    }

    private function newGroup(): string
    {
        for ($i = 1; isset($this->groups['item'.$i]); ++$i) {
        }

        $this->groups['item'.$i] = true;

        return 'item'.$i;
    }

    /**
     * The components of an N value. Secondary surnames also go after the family names, and
     * generations before the honorific suffixes (RFC 9555, Table 1): in vCard 3.0, only
     * there.
     *
     * @param array<string, list<string>> $values Values by NameComponent kind
     *
     * @return list<list<string>>
     */
    private function nameLayout(array $values, bool $isV40): array
    {
        $components = [
            [...$values['surname'] ?? [], ...$values['surname2'] ?? []],
            $values['given'] ?? [],
            $values['given2'] ?? [],
            $values['title'] ?? [],
            [...$values['generation'] ?? [], ...$values['credential'] ?? []],
        ];
        if ($isV40) {
            $components[] = $values['surname2'] ?? [];
            $components[] = $values['generation'] ?? [];
        }

        return $components;
    }

    /**
     * A full name from name components, for the FN property (RFC 9555, section 3.1).
     */
    private function fullName(Name $name): string
    {
        $components = $name->components;
        if (!$name->isOrdered) {
            // Western order, the most common one.
            $order = array_flip(['title', 'given', 'given2', 'surname', 'surname2', 'generation', 'credential']);
            usort($components, static fn ($a, $b): int => ($order[$a->kind] ?? 99) <=> ($order[$b->kind] ?? 99));
        }

        $full = '';
        $separator = null;
        foreach ($components as $component) {
            if ('separator' === $component->kind) {
                $separator = ($separator ?? '').$component->value;
                continue;
            }

            if ('' !== $full) {
                $full .= $separator ?? $name->defaultSeparator ?? ' ';
            }

            $full .= $component->value;
            $separator = null;
        }

        return $full;
    }

    /**
     * A structured value for sabre/vobject: a component with one value is a string, one
     * with several values a list.
     *
     * @param list<list<string>> $components
     *
     * @return list<string|list<string>>
     */
    private function structured(array $components): array
    {
        return array_map(static fn (array $values): string|array => 1 === \count($values) ? $values[0] : ([] === $values ? '' : $values), $components);
    }

    private function escape(string $token): string
    {
        return strtr($token, ['~' => '~0', '/' => '~1']);
    }
}
