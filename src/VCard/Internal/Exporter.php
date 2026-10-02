<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Conversion\IssueCollector;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Calendar;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\Directory;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Media;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\PersonalInfo;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\Registry;
use Rondeto\JSContact\Validation\Syntax;
use Rondeto\JSContact\VCard\VCardVersion;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Property\Uri;
use Sabre\VObject\Reader;

/**
 * Converts a Card to a vCard, by the reverse rules of RFC 9555 (section 3).
 *
 * @internal
 */
final class Exporter
{
    /** vCard 4.0 properties that vCard 3.0 does not define. */
    private const array NOT_IN_V30 = ['KIND', 'MEMBER', 'LANGUAGE', 'CREATED', 'SOCIALPROFILE', 'CONTACT-URI', 'ANNIVERSARY', 'DEATHDATE', 'BIRTHPLACE', 'DEATHPLACE', 'GRAMGENDER', 'PRONOUNS', 'ORG-DIRECTORY',
        'LANG', 'RELATED', 'EXPERTISE', 'HOBBY', 'INTEREST',
    ];

    /** PersonalInfo levels and the LEVEL values of EXPERTISE they convert to (RFC 9555, section 2.3.13). */
    private const array EXPERTISE_LEVELS = ['low' => 'beginner', 'medium' => 'average', 'high' => 'expert'];

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
            $this->address((string) $key, $address, \count($card->addresses));
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

        $this->anniversaries($card);
        $this->speakToAs($card);
        $this->resources($card);
        $this->languagesRelationsAndPersonalInfo($card);

        $organizationGroups = $this->organizationGroups($card);
        foreach ($card->organizations as $key => $organization) {
            $this->organization((string) $key, $organization, $organizationGroups[$key] ?? null);
        }

        foreach ($card->titles as $key => $title) {
            $group = null === $title->organizationId ? null : ($organizationGroups[$title->organizationId] ?? null);
            $this->title((string) $key, $title, $group);
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

    private function address(string $key, Address $address, int $count): void
    {
        $path = '/addresses/'.$key;
        if ($this->isGeographyOnly($address)) {
            $this->geography($key, $address, $path, $count > 1);

            return;
        }

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

    /**
     * An address with nothing but coordinates and a time zone, which converts to GEO and TZ
     * rather than to an empty ADR (RFC 9555, section 2.8).
     */
    private function isGeographyOnly(Address $address): bool
    {
        return (null !== $address->coordinates || null !== $address->timeZone)
            && [] === $address->components && null === $address->full && null === $address->countryCode
            && null === $address->phoneticSystem && null === $address->phoneticScript;
    }

    /**
     * GEO and TZ. When both are set and the Card has other addresses, they share a group so
     * that they convert back to one address (RFC 9555, section 2.8.3); alone, ungrouped GEO
     * and TZ convert back to one address anyway. The key, contexts and preference go on the
     * first one.
     */
    private function geography(string $key, Address $address, string $path, bool $hasOtherAddresses): void
    {
        $values = [];
        if (null !== $address->coordinates) {
            $value = Geography::formatCoordinates($address->coordinates, $this->version);
            if (null === $value) {
                $this->issues->add($path.'/coordinates', 'vCard 3.0 GEO is a latitude and longitude pair, wrote the URI anyway');
                $value = $address->coordinates;
            }

            $values['GEO'] = [$value, []];
        }

        if (null !== $address->timeZone) {
            $offset = VCardVersion::V30 === $this->version ? Geography::utcOffset($address->timeZone) : null;
            // vCard 3.0 TZ is a UTC offset by default, vCard 4.0 TZ a text.
            $values['TZ'] = null !== $offset ? [$offset, []] : [$address->timeZone, VCardVersion::V30 === $this->version ? ['VALUE' => 'text'] : []];
        }

        $group = \is_string($address->vCardParams['group'] ?? null) ? $address->vCardParams['group'] : null;
        if (\count($values) > 1 && $hasOtherAddresses) {
            $group ??= $this->newGroup();
        }

        $first = true;
        foreach ($values as $name => [$value, $params]) {
            if ($first) {
                $this->entry($name, $value, $key, $address->contexts, $address->pref, null, $address->vCardParams, $path, $params, group: $group, raw: true);
                $first = false;
            } else {
                $this->add($name, $value, $params, $group, $path, raw: true);
            }
        }
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

    /**
     * Media, crypto keys, directories, calendars and scheduling addresses (RFC 9555, sections
     * 2.4.3, 2.5.7, 2.9.2, 2.10.4, 2.11.7, 2.12.1 and 2.13). Kinds vCard has no property for
     * are written as JSPROP.
     */
    private function resources(Card $card): void
    {
        $unwritten = [];
        foreach ($card->media as $key => $media) {
            $name = match ($media->kind) {
                Media::KIND_PHOTO => 'PHOTO',
                Media::KIND_LOGO => 'LOGO',
                Media::KIND_SOUND => 'SOUND',
                default => null,
            };
            if (null === $name) {
                $this->issues->add('/media/'.$key, \sprintf('vCard has no media of kind "%s", wrote it as JSPROP', $media->kind));
                $unwritten[$key] = $media;
                continue;
            }

            $this->resource($name, (string) $key, $media->uri, $media->mediaType, $media->contexts, $media->pref, $media->label, $media->vCardParams, '/media/'.$key);
        }

        $this->unwrittenEntries('media', new Card(media: $unwritten), \count($card->media));

        foreach ($card->cryptoKeys as $key => $cryptoKey) {
            $this->resource('KEY', (string) $key, $cryptoKey->uri, $cryptoKey->mediaType, $cryptoKey->contexts, $cryptoKey->pref, $cryptoKey->label, $cryptoKey->vCardParams, '/cryptoKeys/'.$key);
            if (null !== $cryptoKey->kind) {
                $this->issues->add('/cryptoKeys/'.$key.'/kind', 'vCard has no kind of key, wrote it as JSPROP');
                $this->jsProp('cryptoKeys/'.$this->escape((string) $key).'/kind', $cryptoKey->kind);
            }
        }

        $unwritten = [];
        foreach ($card->directories as $key => $directory) {
            $name = match ($directory->kind) {
                Directory::KIND_ENTRY => 'SOURCE',
                Directory::KIND_DIRECTORY => 'ORG-DIRECTORY',
                default => null,
            };
            if (null === $name) {
                $this->issues->add('/directories/'.$key, \sprintf('vCard has no directory of kind "%s", wrote it as JSPROP', $directory->kind));
                $unwritten[$key] = $directory;
                continue;
            }

            $params = null === $directory->listAs ? [] : ['INDEX' => (string) $directory->listAs];
            $this->resource($name, (string) $key, $directory->uri, $directory->mediaType, $directory->contexts, $directory->pref, $directory->label, $directory->vCardParams, '/directories/'.$key, $params);
        }

        $this->unwrittenEntries('directories', new Card(directories: $unwritten), \count($card->directories));

        $unwritten = [];
        foreach ($card->calendars as $key => $calendar) {
            $name = match ($calendar->kind) {
                Calendar::KIND_CALENDAR => 'CALURI',
                Calendar::KIND_FREE_BUSY => 'FBURL',
                default => null,
            };
            if (null === $name) {
                $this->issues->add('/calendars/'.$key, \sprintf('vCard has no calendar of kind "%s", wrote it as JSPROP', $calendar->kind));
                $unwritten[$key] = $calendar;
                continue;
            }

            $this->resource($name, (string) $key, $calendar->uri, $calendar->mediaType, $calendar->contexts, $calendar->pref, $calendar->label, $calendar->vCardParams, '/calendars/'.$key);
        }

        $this->unwrittenEntries('calendars', new Card(calendars: $unwritten), \count($card->calendars));

        foreach ($card->schedulingAddresses as $key => $address) {
            $this->resource('CALADRURI', (string) $key, $address->uri, null, $address->contexts, $address->pref, $address->label, $address->vCardParams, '/schedulingAddresses/'.$key);
        }
    }

    /**
     * Preferred languages convert to LANG, relations to RELATED, personal information to
     * EXPERTISE, HOBBY or INTEREST (RFC 9555, sections 2.7.3, 2.9.5 and 2.10).
     */
    private function languagesRelationsAndPersonalInfo(Card $card): void
    {
        foreach ($card->preferredLanguages as $key => $language) {
            $this->entry('LANG', $language->language, (string) $key, $language->contexts, $language->pref, null, $language->vCardParams, '/preferredLanguages/'.$key);
        }

        foreach ($card->relatedTo as $key => $relation) {
            $key = (string) $key;
            $path = '/relatedTo/'.$this->escape($key);
            $types = [];
            foreach ($relation->relation as $type) {
                if (1 === preg_match('/^[a-z0-9-]+$/i', $type)) {
                    $types[] = $type;
                } else {
                    $this->issues->add($path.'/relation/'.$this->escape($type), 'vCard has no TYPE for this relation, wrote it as JSPROP');
                    $this->jsProp('relatedTo/'.$this->escape($key).'/relation/'.$this->escape($type), true);
                }
            }

            $params = $this->parameters($relation->vCardParams);
            if ([] !== $types) {
                $params['TYPE'] = $types;
            }

            $group = $relation->vCardParams['group'] ?? null;
            $group = \is_string($group) ? $group : null;
            // A related entity is a URI by default, or text with VALUE=text.
            if (1 === preg_match(Syntax::URI, $key)) {
                $this->add('RELATED', $key, $params, $group, $path, raw: true);
            } else {
                $this->add('RELATED', $key, ['VALUE' => 'text', ...$params], $group, $path);
            }
        }

        $unwritten = [];
        foreach ($card->personalInfo as $key => $info) {
            $name = match ($info->kind) {
                PersonalInfo::KIND_EXPERTISE => 'EXPERTISE',
                PersonalInfo::KIND_HOBBY => 'HOBBY',
                PersonalInfo::KIND_INTEREST => 'INTEREST',
                default => null,
            };
            if (null === $name) {
                $this->issues->add('/personalInfo/'.$key, \sprintf('vCard has no personal information of kind "%s", wrote it as JSPROP', $info->kind));
                $unwritten[$key] = $info;
                continue;
            }

            $params = [];
            if (null !== $info->level) {
                $params['LEVEL'] = 'EXPERTISE' === $name ? (self::EXPERTISE_LEVELS[$info->level] ?? $info->level) : $info->level;
            }

            if (null !== $info->listAs) {
                $params['INDEX'] = (string) $info->listAs;
            }

            $this->entry($name, $info->value, (string) $key, [], null, $info->label, $info->vCardParams, '/personalInfo/'.$key, $params);
        }

        $this->unwrittenEntries('personalInfo', new Card(personalInfo: $unwritten), \count($card->personalInfo));
    }

    /**
     * Writes a resource, as is. vCard 3.0 embeds media as binary, with their format as a TYPE
     * value, and gives other values as URIs with VALUE=uri.
     *
     * @param list<string>                       $contexts
     * @param array<string, string|list<string>> $vCardParams
     * @param array<string, string>              $params
     */
    private function resource(string $name, string $key, string $uri, ?string $mediaType, array $contexts, ?int $pref, ?string $label, array $vCardParams, string $path, array $params = []): void
    {
        $types = [];
        $value = $uri;
        $isMedia = \in_array($name, ['PHOTO', 'LOGO', 'SOUND', 'KEY'], true);
        if (VCardVersion::V30 === $this->version && $isMedia) {
            if (1 === preg_match('/^data:([^;,]*)(?:;[^;,]*)*;base64,(.*)$/s', $uri, $matches)) {
                $params['ENCODING'] = 'b';
                $value = $matches[2];
                $mediaType ??= '' === $matches[1] ? null : $matches[1];
            } else {
                $params['VALUE'] = 'uri';
            }

            $format = null === $mediaType ? null : MediaTypes::toFormat($mediaType);
            if (null !== $format) {
                $types[] = $format;
                $mediaType = null;
            }
        }

        if (null !== $mediaType && !isset($params['ENCODING'])) {
            $params['MEDIATYPE'] = $mediaType;
        }

        $this->entry($name, $value, $key, $contexts, $pref, $label, $vCardParams, $path, $params, $types, raw: true);
    }

    /**
     * Entries vCard cannot hold, written as JSPROP. A pointer needs its parent to exist: the
     * whole map when no entry of it was written.
     *
     * @param Card $unwritten A Card with only the entries not written, in the map
     */
    private function unwrittenEntries(string $map, Card $unwritten, int $count): void
    {
        $entries = $this->json($unwritten, $map);
        if (!$entries instanceof \stdClass) {
            return;
        }

        if (\count(get_object_vars($entries)) === $count) {
            $this->jsProp($map, $entries);

            return;
        }

        foreach (get_object_vars($entries) as $key => $entry) {
            $this->jsProp($map.'/'.$this->escape((string) $key), $entry);
        }
    }

    /**
     * The grammatical gender converts to GRAMGENDER, pronouns to PRONOUNS (RFC 9555, section
     * 2.5.4). A vendor-specific gender has no vCard value: it is written as JSPROP.
     */
    private function speakToAs(Card $card): void
    {
        $speakToAs = $card->speakToAs;
        if (null === $speakToAs) {
            return;
        }

        $gender = $speakToAs->grammaticalGender;
        if (null !== $gender && 1 === preg_match('/^[a-z0-9-]+$/', $gender)) {
            $group = $speakToAs->vCardParams['group'] ?? null;
            $this->add('GRAMGENDER', $gender, $this->parameters($speakToAs->vCardParams), \is_string($group) ? $group : null, '/speakToAs/grammaticalGender');
        } elseif (null !== $gender) {
            $this->issues->add('/speakToAs/grammaticalGender', \sprintf('vCard has no grammatical gender "%s", wrote it as JSPROP', $gender));
            // A JSPROP needs its parent to exist: the whole object when there are no pronouns.
            if ([] === $speakToAs->pronouns) {
                $this->jsProp('speakToAs', $this->json(new Card(speakToAs: $speakToAs), 'speakToAs'));

                return;
            }

            $this->jsProp('speakToAs/grammaticalGender', $gender);
        } elseif ([] !== $speakToAs->vCardParams) {
            $this->issues->add('/speakToAs/vCardParams', 'no GRAMGENDER property to write them on, left them out');
        }

        foreach ($speakToAs->pronouns as $key => $pronouns) {
            $this->entry('PRONOUNS', $pronouns->pronouns, (string) $key, $pronouns->contexts, $pronouns->pref, null, $pronouns->vCardParams, '/speakToAs/pronouns/'.$key);
            foreach ($pronouns->extra as $name => $value) {
                $this->jsProp('speakToAs/pronouns/'.$this->escape((string) $key).'/'.$this->escape((string) $name), $value);
            }
        }

        foreach ($speakToAs->extra as $name => $value) {
            $this->jsProp('speakToAs/'.$this->escape((string) $name), $value);
        }
    }

    /**
     * Anniversaries convert to BDAY, DEATHDATE or ANNIVERSARY, and their place to BIRTHPLACE
     * or DEATHPLACE (RFC 9555, section 2.5.1). What vCard cannot hold is written as JSPROP.
     */
    private function anniversaries(Card $card): void
    {
        $unwritten = [];
        foreach ($card->anniversaries as $key => $anniversary) {
            $key = (string) $key;
            $path = '/anniversaries/'.$key;
            [$name, $placeName] = match ($anniversary->kind) {
                Anniversary::KIND_BIRTH => ['BDAY', 'BIRTHPLACE'],
                Anniversary::KIND_DEATH => ['DEATHDATE', 'DEATHPLACE'],
                Anniversary::KIND_WEDDING => ['ANNIVERSARY', null],
                default => [null, null],
            };
            $value = Dates::format($anniversary->date, $this->version);
            if (null === $name || null === $value) {
                $this->issues->add($path, null === $name
                    ? \sprintf('vCard has no anniversary of kind "%s", wrote it as JSPROP', $anniversary->kind)
                    : 'vCard 3.0 has no partial dates, wrote the anniversary as JSPROP');
                $unwritten[$key] = $anniversary;
                continue;
            }

            $params = [];
            if ($anniversary->date instanceof PartialDate && null !== $anniversary->date->calendarScale) {
                if (VCardVersion::V30 === $this->version) {
                    $this->issues->add($path.'/date/calendarScale', 'vCard 3.0 has no CALSCALE, left it out');
                } else {
                    $params['CALSCALE'] = $anniversary->date->calendarScale;
                }
            }

            $this->entry($name, $value, $key, [], null, null, $anniversary->vCardParams, $path, $params);

            $place = $anniversary->place;
            if (null === $place) {
                continue;
            }

            if (null !== $placeName && null !== ($placeValue = $this->placeValue($place))) {
                $placeParams = $this->parameters($place->vCardParams);
                if (null !== $place->coordinates) {
                    $placeParams['VALUE'] = 'uri';
                }

                $group = $place->vCardParams['group'] ?? null;
                $this->add($placeName, $placeValue, $placeParams, \is_string($group) ? $group : null, $path.'/place');
            } else {
                $this->issues->add($path.'/place', 'vCard cannot hold this place, wrote it as JSPROP');
                $this->jsProp('anniversaries/'.$this->escape($key).'/place', $this->json(new Card(anniversaries: [$key => $anniversary]), 'anniversaries', $key, 'place'));
            }
        }

        // A JSPROP needs its parent to exist: the whole map when no anniversary was written.
        if ([] !== $unwritten && \count($unwritten) === \count($card->anniversaries)) {
            $this->jsProp('anniversaries', $this->json(new Card(anniversaries: $unwritten), 'anniversaries'));
        } else {
            foreach ($unwritten as $key => $anniversary) {
                $this->jsProp('anniversaries/'.$this->escape($key), $this->json(new Card(anniversaries: [$key => $anniversary]), 'anniversaries', $key));
            }
        }
    }

    /**
     * The value of a BIRTHPLACE or DEATHPLACE: the full address as text, or the coordinates
     * as a "geo:" URI. Null if the place holds anything else.
     */
    private function placeValue(Address $place): ?string
    {
        $isFull = null !== $place->full && null === $place->coordinates;
        $isCoordinates = null === $place->full && null !== $place->coordinates;
        $hasMore = [] !== $place->components || null !== $place->countryCode || null !== $place->timeZone
            || [] !== $place->contexts || null !== $place->pref || null !== $place->phoneticSystem
            || null !== $place->phoneticScript || [] !== $place->extra;

        return $hasMore || (!$isFull && !$isCoordinates) ? null : ($place->full ?? $place->coordinates);
    }

    /**
     * Part of the JSON form of a Card, for JSPROP.
     */
    private function json(Card $card, string ...$path): mixed
    {
        $value = new JsonEncoder(validate: false)->normalize($card);
        foreach ($path as $token) {
            $value = $value instanceof \stdClass ? ($value->{$token} ?? null) : null;
        }

        return $value;
    }

    /**
     * The group of each organization a title is held in, so that the TITLE or ROLE property
     * can share it (RFC 9555, section 2.9.6): the one it was read with, or a new one.
     *
     * @return array<array-key, string>
     */
    private function organizationGroups(Card $card): array
    {
        $groups = [];
        foreach ($card->titles as $title) {
            $organization = null === $title->organizationId ? null : ($card->organizations[$title->organizationId] ?? null);
            if (null !== $organization && !isset($groups[$title->organizationId])) {
                $group = $organization->vCardParams['group'] ?? null;
                $groups[$title->organizationId] = \is_string($group) ? $group : $this->newGroup();
            }
        }

        return $groups;
    }

    private function organization(string $key, Organization $organization, ?string $group): void
    {
        $params = [];
        $sortAs = [$organization->sortAs ?? '', ...array_map(static fn (OrgUnit $unit): string => $unit->sortAs ?? '', $organization->units)];
        if ('' !== implode('', $sortAs)) {
            $params['SORT-AS'] = rtrim(implode(',', $sortAs), ',');
        }

        $value = [$organization->name ?? '', ...array_map(static fn (OrgUnit $unit): string => $unit->name, $organization->units)];
        $this->entry('ORG', $value, $key, $organization->contexts, null, null, $organization->vCardParams, '/organizations/'.$key, $params, group: $group);
    }

    private function title(string $key, Title $title, ?string $group): void
    {
        $name = Title::KIND_ROLE === $title->kind ? 'ROLE' : 'TITLE';
        if (null !== $title->kind && !\in_array($title->kind, [Title::KIND_TITLE, Title::KIND_ROLE], true)) {
            $this->issues->add('/titles/'.$key.'/kind', \sprintf('vCard has no title of kind "%s", wrote a TITLE', $title->kind));
        }

        $this->entry($name, $title->name, $key, [], null, null, $title->vCardParams, '/titles/'.$key, group: $group);
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
     * @param string|null                        $group       The group to write the property in, if not that of vCardParams
     * @param bool                               $raw         Whether to write the value as is, see add()
     */
    private function entry(string $name, string|array $value, string $key, array $contexts, ?int $pref, ?string $label, array $vCardParams, string $path, array $params = [], array $types = [], ?string $group = null, bool $raw = false): void
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

        $group ??= \is_string($vCardParams['group'] ?? null) ? $vCardParams['group'] : null;
        if (null !== $label) {
            $group ??= $this->newGroup();
        }

        $this->add($name, $value, $params, $group, $path, $raw);
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
                    // sabre/vobject workaround: jCard keeps the raw value of unknown types (RFC
                    // 7095, section 5), but sabre escapes it again when writing; it also adds a
                    // VALUE=UNKNOWN parameter, which is not a vCard value type.
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
            'links' => $card->links, 'notes' => $card->notes, 'organizations' => $card->organizations,
            'titles' => $card->titles, 'anniversaries' => $card->anniversaries, 'media' => $card->media,
            'cryptoKeys' => $card->cryptoKeys, 'directories' => $card->directories, 'calendars' => $card->calendars,
            'schedulingAddresses' => $card->schedulingAddresses, 'preferredLanguages' => $card->preferredLanguages,
            'relatedTo' => $card->relatedTo, 'personalInfo' => $card->personalInfo,
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
                    $this->arrayItemExtras('/addresses/'.$key.'/components', $entry->components);
                }

                if ($entry instanceof Anniversary) {
                    foreach ($entry->date->extra as $name => $value) {
                        $this->jsProp('anniversaries/'.$this->escape((string) $key).'/date/'.$this->escape((string) $name), $value);
                    }
                }

                if ($entry instanceof Organization) {
                    $this->arrayItemExtras('/organizations/'.$key.'/units', $entry->units);
                }
            }
        }

        if (null !== $card->name) {
            foreach ($card->name->extra as $name => $value) {
                $this->jsProp('name/'.$this->escape((string) $name), $value);
            }

            $this->arrayItemExtras('/name/components', $card->name->components);
        }
    }

    /**
     * Objects in arrays (name and address components, organizational units) cannot be
     * pointed at by JSPROP (RFC 9555, section 3.2.1).
     *
     * @param list<object{extra: array<array-key, mixed>}> $items
     */
    private function arrayItemExtras(string $path, array $items): void
    {
        foreach ($items as $index => $item) {
            if ([] !== $item->extra) {
                $this->issues->add($path.'/'.$index, 'properties of objects in arrays cannot be written to vCard, left them out');
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
     * @param bool                               $raw    whether to write the value as is, without escaping
     *
     * sabre/vobject workaround ($raw): depending on the property and the version, sabre picks a
     * binary class that encodes a URI in base64, or a text class that escapes its commas
     */
    private function add(string $name, string|array $value, array $params = [], ?string $group = null, string $path = '', bool $raw = false): void
    {
        if (VCardVersion::V30 === $this->version && \in_array($name, self::NOT_IN_V30, true)) {
            $this->issues->add($path, \sprintf('vCard 3.0 does not define %s, wrote it anyway', $name));
        }

        if ($raw && \is_string($value)) {
            $property = new RawProperty($this->vCard, $name, null, $params, $group);
            $property->setRawMimeDirValue($value);
            $this->vCard->add($property);

            return;
        }

        $property = $this->vCard->add((null === $group ? '' : $group.'.').$name, $value, $params);

        // sabre/vobject workaround: sabre escapes commas in URI values ("geo:1\,2"), which
        // RFC 6350 does not, and does not unescape them when reading URL. Write URIs as is.
        if ($property instanceof Uri && \is_string($value)) {
            $this->vCard->remove($property);
            $raw = new RawProperty($this->vCard, $property->name ?? $name, null, $params, $group);
            $raw->setRawMimeDirValue($value);
            $this->vCard->add($raw);
        }
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
        $objects = [$card->name, $card->speakToAs, ...$card->preferredLanguages, ...$card->relatedTo, ...$card->personalInfo, ...$card->media, ...$card->cryptoKeys, ...$card->directories, ...$card->calendars, ...$card->schedulingAddresses, ...$card->speakToAs->pronouns ?? [], ...$card->nicknames, ...$card->organizations, ...$card->titles, ...$card->anniversaries, ...$card->emails, ...$card->phones, ...$card->addresses, ...$card->onlineServices, ...$card->links, ...$card->notes];
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
