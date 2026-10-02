<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard\Internal;

use Rondeto\JSContact\Conversion\IssueCollector;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Author;
use Rondeto\JSContact\Model\Calendar;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\CryptoKey;
use Rondeto\JSContact\Model\Directory;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\LanguagePref;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Media;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\PersonalInfo;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\Model\SchedulingAddress;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\CardValidator;
use Rondeto\JSContact\Validation\Syntax;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Property\Binary;
use Sabre\VObject\Property\Unknown;

/**
 * Converts one vCard to a Card (RFC 9555, section 2, as updated by RFC 9982).
 *
 * Each vCard property either converts entirely, its unknown parameters going to
 * vCardParams, or is kept verbatim in vCardProps, with an issue when that is not the
 * expected outcome.
 *
 * @internal
 */
final class Importer
{
    /** Maps, and the prefix of their generated keys (as in the examples of RFC 9555), by vCard property. */
    private const array ENTRIES = [
        'NICKNAME' => ['nicknames', 'NICK'],
        'EMAIL' => ['emails', 'EMAIL'],
        'TEL' => ['phones', 'PHONE'],
        'ADR' => ['addresses', 'ADDR'],
        'IMPP' => ['onlineServices', 'OS'],
        'SOCIALPROFILE' => ['onlineServices', 'OS'],
        'URL' => ['links', 'LINK'],
        'CONTACT-URI' => ['links', 'CONTACT'],
        'NOTE' => ['notes', 'NOTE'],
        'ORG' => ['organizations', 'ORG'],
        // Titles and roles share their keys, as in RFC 9555, Figure 27.
        'TITLE' => ['titles', 'TITLE'],
        'ROLE' => ['titles', 'TITLE'],
        // Anniversaries are named after ANNIVERSARY, as in RFC 9555, Figure 9.
        'BDAY' => ['anniversaries', 'ANNIVERSARY'],
        'DEATHDATE' => ['anniversaries', 'ANNIVERSARY'],
        'ANNIVERSARY' => ['anniversaries', 'ANNIVERSARY'],
        'PRONOUNS' => ['pronouns', 'PRONOUNS'],
        'PHOTO' => ['media', 'PHOTO'],
        'LOGO' => ['media', 'LOGO'],
        'SOUND' => ['media', 'SOUND'],
        'KEY' => ['cryptoKeys', 'KEY'],
        'SOURCE' => ['directories', 'ENTRY'],
        'ORG-DIRECTORY' => ['directories', 'DIRECTORY'],
        'CALURI' => ['calendars', 'CAL'],
        'FBURL' => ['calendars', 'FBURL'],
        'CALADRURI' => ['schedulingAddresses', 'SCHEDULING'],
        'LANG' => ['preferredLanguages', 'LANG'],
        // Personal information is named after PERSINFO, as in RFC 9555, Figures 28 to 30.
        'EXPERTISE' => ['personalInfo', 'PERSINFO'],
        'HOBBY' => ['personalInfo', 'PERSINFO'],
        'INTEREST' => ['personalInfo', 'PERSINFO'],
    ];

    /** vCard properties whose JSContact object has a label property, set from X-ABLabel. */
    private const array LABELED = [
        'EMAIL', 'TEL', 'IMPP', 'SOCIALPROFILE', 'URL', 'CONTACT-URI', 'PHOTO', 'LOGO', 'SOUND', 'KEY', 'SOURCE',
        'ORG-DIRECTORY', 'CALURI', 'FBURL', 'CALADRURI', 'EXPERTISE', 'HOBBY', 'INTEREST',
    ];

    /** LEVEL values of EXPERTISE, and the PersonalInfo level they convert to (RFC 9555, section 2.3.13). */
    private const array EXPERTISE_LEVELS = ['beginner' => 'low', 'average' => 'medium', 'expert' => 'high'];

    /** Resources whose format vCard 3.0 gives as a TYPE value, or embeds as binary. */
    private const array MEDIA = ['PHOTO', 'LOGO', 'SOUND', 'KEY'];

    /** TEL TYPE values and the Phone features they convert to (RFC 9555, Table 3). */
    private const array PHONE_FEATURES = [
        'cell' => 'mobile', 'fax' => 'fax', 'main-number' => 'main-number', 'pager' => 'pager',
        'text' => 'text', 'textphone' => 'textphone', 'video' => 'video', 'voice' => 'voice',
    ];

    private readonly IssueCollector $issues;

    private readonly KeyAllocator $keys;

    /** @var array<int, string> Raw values of N, ADR and unknown properties, by object id */
    private array $raw = [];

    /** @var array<string, PropertyReader> X-ABLabel properties not used yet, by group */
    private array $labels = [];

    private ?string $uid = null;

    private ?string $prodId = null;

    private ?string $language = null;

    private ?string $kind = null;

    private ?\DateTimeImmutable $updated = null;

    private ?\DateTimeImmutable $created = null;

    /** @var array<string, true> Single properties already converted, by vCard name */
    private array $converted = [];

    /** @var list<string> */
    private array $members = [];

    /** @var list<string> */
    private array $keywords = [];

    /** @var array<string, Nickname> */
    private array $nicknames = [];

    /** @var array<string, EmailAddress> */
    private array $emails = [];

    /** @var array<string, Phone> */
    private array $phones = [];

    /** @var array<string, Address> */
    private array $addresses = [];

    /** @var array<string, OnlineService> */
    private array $onlineServices = [];

    /** @var array<string, Link> */
    private array $links = [];

    /** @var array<string, Organization> */
    private array $organizations = [];

    /** @var array<string, Title> */
    private array $titles = [];

    /** @var array<string, Pronouns> */
    private array $pronouns = [];

    private ?PropertyReader $grammaticalGender = null;

    /** @var array<string, Media> */
    private array $media = [];

    /** @var array<string, CryptoKey> */
    private array $cryptoKeys = [];

    /** @var array<string, Directory> */
    private array $directories = [];

    /** @var array<string, Calendar> */
    private array $calendars = [];

    /** @var array<string, SchedulingAddress> */
    private array $schedulingAddresses = [];

    /** @var array<string, LanguagePref> */
    private array $preferredLanguages = [];

    /** @var array<string, Relation> */
    private array $relatedTo = [];

    /** @var array<string, PersonalInfo> */
    private array $personalInfo = [];

    /** @var array<string, Anniversary> */
    private array $anniversaries = [];

    /** @var list<PropertyReader> BDAY, DEATHDATE and ANNIVERSARY properties, in order */
    private array $dates = [];

    /** @var array<string, list<PropertyReader>> BIRTHPLACE and DEATHPLACE properties, by name */
    private array $places = [];

    /** @var array<string, Note> */
    private array $notes = [];

    /** @var list<VCardProperty> */
    private array $vCardProps = [];

    /** @var list<PropertyReader> */
    private array $names = [];

    /** @var list<PropertyReader> */
    private array $fullNames = [];

    /** @var list<PropertyReader> */
    private array $phonetics = [];

    /** @var list<PropertyReader> */
    private array $jsProps = [];

    /**
     * @param array<string, list<string>> $rawValues Raw values of each property, in order (see Parser)
     */
    public function __construct(
        private readonly array $rawValues,
        private readonly CardValidator $validator,
    ) {
        $this->issues = new IssueCollector();
        $this->keys = new KeyAllocator();
    }

    /**
     * @param list<string> $parseIssues
     *
     * @return Result<Card>
     */
    public function import(VCard $vCard, array $parseIssues = []): Result
    {
        foreach ($parseIssues as $issue) {
            $this->issues->add('', $issue);
        }

        $properties = [];
        foreach ($vCard->children() as $property) {
            if ($property instanceof Property) {
                $properties[] = new PropertyReader($property);
            }
        }

        $alternatives = $this->alternatives($properties);
        $this->matchRawValues($properties);
        $this->collectLabels($properties);
        foreach ($properties as $property) {
            $propId = $property->peek('PROP-ID');
            if (isset(self::ENTRIES[$property->name]) && null !== $propId && Syntax::isId($propId)) {
                $this->keys->reserve(self::ENTRIES[$property->name][0], $propId);
            }
        }

        foreach ($properties as $property) {
            if (\in_array($property, $alternatives, true)) {
                $this->raw($property, 'localized alternatives (ALTID with LANGUAGE) are not converted yet');
            } else {
                $this->property($property);
            }
        }

        $name = $this->name();
        $this->linkTitles();
        $this->anniversaries();
        foreach ($this->labels as $label) {
            $this->raw($label); // No entry of its group has a label property.
        }

        $card = new Card(
            uid: $this->uid,
            prodId: $this->prodId,
            created: $this->created,
            updated: $this->updated,
            kind: $this->kind,
            language: $this->language,
            members: $this->members,
            name: $name,
            speakToAs: $this->speakToAs(),
            nicknames: $this->nicknames,
            organizations: $this->organizations,
            titles: $this->titles,
            emails: $this->emails,
            phones: $this->phones,
            addresses: $this->addresses,
            onlineServices: $this->onlineServices,
            links: $this->links,
            notes: $this->notes,
            anniversaries: $this->anniversaries,
            media: $this->media,
            cryptoKeys: $this->cryptoKeys,
            directories: $this->directories,
            calendars: $this->calendars,
            schedulingAddresses: $this->schedulingAddresses,
            preferredLanguages: $this->preferredLanguages,
            relatedTo: $this->relatedTo,
            personalInfo: $this->personalInfo,
            keywords: array_values(array_unique($this->keywords)),
            vCardProps: $this->vCardProps,
        );

        if ([] !== $this->jsProps) {
            return $this->patch($card);
        }

        return new Result($card, [...$this->issues->all(), ...$this->validator->validate($card)]);
    }

    private function property(PropertyReader $property): void
    {
        match ($property->name) {
            'UID', 'PRODID', 'LANGUAGE', 'KIND', 'REV', 'CREATED' => $this->single($property),
            'MEMBER' => $this->textList($property, $this->members),
            'CATEGORIES' => $this->textList($property, $this->keywords),
            'N' => $property->has('PHONETIC') ? $this->phonetics[] = $property : $this->names[] = $property,
            'FN' => $this->fullNames[] = $property,
            'X-ABLABEL' => null, // Collected beforehand.
            // RFC 9555 (section 2.11.10) keeps it in vCardProps, but it only matters to the
            // vCard being read: converting back writes the version asked for.
            'VERSION' => null,
            'JSPROP' => $this->jsProps[] = $property,
            'NICKNAME' => $this->nickname($property),
            'EMAIL' => $this->email($property),
            'TEL' => $this->phone($property),
            'ADR' => $this->address($property),
            'IMPP', 'SOCIALPROFILE' => $this->onlineService($property),
            'URL', 'CONTACT-URI' => $this->link($property),
            'NOTE' => $this->note($property),
            'ORG' => $this->organization($property),
            'TITLE', 'ROLE' => $this->title($property),
            'BDAY', 'DEATHDATE', 'ANNIVERSARY' => $this->dates[] = $property,
            'GRAMGENDER' => $this->grammaticalGender($property),
            'PRONOUNS' => $this->pronoun($property),
            'LANG' => $this->language($property),
            'RELATED' => $this->related($property),
            'EXPERTISE', 'HOBBY', 'INTEREST' => $this->personalInfo($property),
            'PHOTO', 'LOGO', 'SOUND', 'KEY', 'SOURCE', 'ORG-DIRECTORY', 'CALURI', 'FBURL', 'CALADRURI' => $this->resource($property),
            'BIRTHPLACE', 'DEATHPLACE' => $this->places[$property->name][] = $property,
            default => $this->raw($property),
        };
    }

    /**
     * Properties that are localized alternatives of another one: same name and ALTID
     * (RFC 9555, section 2.3.11). They convert to localizations, which are not modeled
     * yet, so all but the first are kept verbatim.
     *
     * @param list<PropertyReader> $properties
     *
     * @return list<PropertyReader>
     */
    private function alternatives(array $properties): array
    {
        $seen = [];
        $alternatives = [];
        foreach ($properties as $property) {
            $altId = $property->peek('ALTID');
            if (null === $altId || $property->has('PHONETIC')) {
                continue;
            }

            if (isset($seen[$property->name][$altId])) {
                $alternatives[] = $property;
            }

            $seen[$property->name][$altId] = true;
        }

        return $alternatives;
    }

    /**
     * sabre/vobject workaround: see Parser for why raw values are needed.
     *
     * Pairs properties with their raw values, checking each pair: should sabre have dropped
     * a line the Parser kept, the values would not match. Only N, ADR and unknown
     * properties need them.
     *
     * @param list<PropertyReader> $properties
     */
    private function matchRawValues(array $properties): void
    {
        $positions = [];
        foreach ($properties as $property) {
            $position = $positions[$property->name] = ($positions[$property->name] ?? -1) + 1;
            $raw = $this->rawValues[$property->name][$position] ?? null;
            $isStructured = \in_array($property->name, ['N', 'ADR'], true);
            if (null === $raw || (!$isStructured && !$property->property instanceof Unknown)) {
                continue;
            }

            $parts = implode(';', array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', array_values($property->property->getParts())));
            $matches = $isStructured
                ? $parts === implode(';', array_map(static fn (array $values): string => implode(',', $values), VCardText::splitStructured($raw)))
                // sabre unescapes unknown values, but splits quoted-printable ones on ";".
                : $parts === VCardText::unescape($raw) || $parts === $raw;
            if ($matches) {
                $this->raw[spl_object_id($property)] = $raw;
            }
        }
    }

    /**
     * Collects the X-ABLabel property of each group (RFC 9555, section 2.11.11). Labels with
     * parameters, without group, or repeated in a group are kept verbatim.
     *
     * @param list<PropertyReader> $properties
     */
    private function collectLabels(array $properties): void
    {
        foreach ($properties as $property) {
            if ('X-ABLABEL' !== $property->name) {
                continue;
            }

            if (null === $property->group || isset($this->labels[$property->group]) || [] !== $property->unreadParameterNames()) {
                $this->raw($property);
            } else {
                $this->labels[$property->group] = $property;
            }
        }
    }

    private function single(PropertyReader $property): void
    {
        if (isset($this->converted[$property->name])) {
            $this->raw($property, \sprintf('more than one %s property', $property->name));

            return;
        }

        $text = trim($property->text());
        $isValid = match ($property->name) {
            'REV', 'CREATED' => null !== Timestamp::parse($text),
            'KIND' => 1 === preg_match('/^[a-z0-9-]+$/i', $text) && 0 !== stripos($text, 'x-'),
            default => '' !== $text,
        };
        if (!$isValid) {
            $this->raw($property, \sprintf('"%s" is not a valid %s value', $text, $property->name));

            return;
        }

        if ([] !== $property->unreadParameters()) {
            $this->raw($property, 'the Card has no place for its parameters or group');

            return;
        }

        $this->converted[$property->name] = true;
        match ($property->name) {
            'UID' => $this->uid = $text,
            'PRODID' => $this->prodId = $text,
            'LANGUAGE' => $this->language = $text,
            'KIND' => $this->kind = strtolower($text),
            'REV' => $this->updated = Timestamp::parse($text),
            default => $this->created = Timestamp::parse($text),
        };
    }

    /**
     * @param list<string> $target
     */
    private function textList(PropertyReader $property, array &$target): void
    {
        if ([] !== $property->unreadParameters()) {
            $this->raw($property, 'the Card has no place for its parameters or group');

            return;
        }

        array_push($target, ...$property->textList());
    }

    private function nickname(PropertyReader $property): void
    {
        $names = $property->textList();
        if ([] === $names) {
            $this->raw($property, 'empty value');

            return;
        }

        // Each value of the list is a nickname; only the first one can take the PROP-ID.
        foreach ($names as $index => $name) {
            $common = $this->common($property, $index > 0);
            $this->nicknames[$common->key] = new Nickname($name, $common->contexts, $common->pref, $common->vCardName, $common->vCardParams);
        }
    }

    private function email(PropertyReader $property): void
    {
        $common = $this->common($property);
        $this->emails[$common->key] = new EmailAddress($property->text(), $common->contexts, $common->pref, $common->label, $common->vCardName, $common->vCardParams);
    }

    private function phone(PropertyReader $property): void
    {
        $features = [];
        foreach (self::PHONE_FEATURES as $type => $feature) {
            if ($property->takeType($type)) {
                $features[] = $feature;
            }
        }

        $common = $this->common($property);
        $this->phones[$common->key] = new Phone($property->text(), $features, $common->contexts, $common->pref, $common->label, $common->vCardName, $common->vCardParams);
    }

    private function address(PropertyReader $property): void
    {
        if ($property->has('PHONETIC')) {
            $this->raw($property, 'phonetic addresses are not converted yet');

            return;
        }

        $structured = $property->structured($this->rawValue($property));
        [$components, $isOrdered, $defaultSeparator] = $this->ordered($property, Components::fromAddress($structured), $structured, Components::ADDRESS_KINDS, '/addresses');
        $label = $property->parameter('LABEL');
        $coordinates = $property->parameter('GEO');
        $timeZone = $property->parameter('TZ');
        $countryCode = $property->parameter('CC');

        if ([] === $components && null === $label && null === $coordinates && null === $timeZone && null === $countryCode) {
            $this->raw($property, 'empty address');

            return;
        }

        $common = $this->common($property);
        $this->addresses[$common->key] = new Address(
            components: array_map(static fn (array $component): AddressComponent => new AddressComponent($component['kind'], $component['value']), $components),
            isOrdered: $isOrdered,
            countryCode: $countryCode,
            coordinates: $coordinates,
            timeZone: $timeZone,
            contexts: $common->contexts,
            full: null === $label ? null : str_replace(['\n', '\N'], "\n", $label),
            defaultSeparator: $defaultSeparator,
            pref: $common->pref,
            vCardName: $common->vCardName,
            vCardParams: $common->vCardParams,
        );
    }

    private function onlineService(PropertyReader $property): void
    {
        // A SOCIALPROFILE of type TEXT is a user name, not a URI (RFC 9555, section 2.7.5).
        $isUser = 'SOCIALPROFILE' === $property->name && 'text' === strtolower($property->peek('VALUE') ?? '');
        $service = $property->parameter('SERVICE-TYPE');
        $user = $isUser ? $property->text() : $property->parameter('USERNAME');

        $common = $this->common($property);
        $this->onlineServices[$common->key] = new OnlineService(
            service: $service,
            uri: $isUser ? null : $property->text(),
            user: $user,
            contexts: $common->contexts,
            pref: $common->pref,
            label: $common->label,
            vCardName: $common->vCardName,
            vCardParams: $common->vCardParams,
        );
    }

    private function link(PropertyReader $property): void
    {
        $mediaType = $property->parameter('MEDIATYPE');
        $common = $this->common($property);
        $this->links[$common->key] = new Link(
            uri: $property->text(),
            kind: 'CONTACT-URI' === $property->name ? Link::KIND_CONTACT : null,
            mediaType: $mediaType,
            contexts: $common->contexts,
            pref: $common->pref,
            label: $common->label,
            vCardName: $common->vCardName,
            vCardParams: $common->vCardParams,
        );
    }

    private function note(PropertyReader $property): void
    {
        $created = $property->parameter('CREATED');
        $createdAt = null === $created ? null : Timestamp::parse($created);
        if (null !== $created && null === $createdAt) {
            $this->issues->add('', \sprintf('NOTE: CREATED "%s" is not a timestamp, ignored it', $created));
        }

        $authorName = $property->parameter('AUTHOR-NAME');
        $authorUri = $property->parameter('AUTHOR');

        $common = $this->common($property);
        $this->notes[$common->key] = new Note(
            note: $property->text(),
            created: $createdAt,
            author: null === $authorName && null === $authorUri ? null : new Author(name: $authorName, uri: $authorUri),
            vCardName: $common->vCardName,
            vCardParams: $common->vCardParams,
        );
    }

    /**
     * An ORG property (RFC 9555, section 2.9.4): the organization name, then its units.
     */
    private function organization(PropertyReader $property): void
    {
        // ORG components are not lists: sabre's parts are right, escaped commas included.
        $parts = array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', array_values($property->property->getParts()));
        while ([] !== $parts && '' === end($parts)) {
            array_pop($parts);
        }

        if ([] === $parts) {
            $this->raw($property, 'empty value');

            return;
        }

        $name = array_shift($parts);
        if (\in_array('', $parts, true)) {
            $this->raw($property, 'an organizational unit has no name');

            return;
        }

        // The first item sorts the organization, the next ones its units.
        $sortAs = [];
        if ($property->has('SORT-AS')) {
            $sortAs = explode(',', implode(',', $property->peekAll('SORT-AS')));
            if (\count($sortAs) > 1 + \count($parts)) {
                $this->issues->add('', 'ORG: SORT-AS has more items than the value has components, kept it in vCardParams');
                $sortAs = [];
            } else {
                $property->parameter('SORT-AS');
            }
        }

        $common = $this->common($property);
        $this->organizations[$common->key] = new Organization(
            name: '' === $name ? null : $name,
            units: array_map(
                static fn (string $unit, int $index): OrgUnit => new OrgUnit($unit, '' === ($sortAs[$index + 1] ?? '') ? null : $sortAs[$index + 1]),
                $parts,
                array_keys($parts),
            ),
            sortAs: '' === ($sortAs[0] ?? '') ? null : $sortAs[0],
            contexts: $common->contexts,
            vCardParams: $common->vCardParams,
        );
    }

    /**
     * A TITLE or ROLE property (RFC 9555, section 2.9.6). Its organization is set once all
     * organizations are read, see linkTitles().
     */
    private function title(PropertyReader $property): void
    {
        $common = $this->common($property);
        $this->titles[$common->key] = new Title(
            name: $property->text(),
            kind: 'ROLE' === $property->name ? Title::KIND_ROLE : Title::KIND_TITLE,
            vCardParams: $common->vCardParams,
        );
    }

    /**
     * Sets the organization of each title whose group holds exactly one ORG property
     * (RFC 9555, section 2.9.6).
     */
    private function linkTitles(): void
    {
        foreach ($this->titles as $key => $title) {
            $group = $title->vCardParams['group'] ?? null;
            if (!\is_string($group)) {
                continue;
            }

            $organizations = array_keys(array_filter($this->organizations, static fn (Organization $organization): bool => ($organization->vCardParams['group'] ?? null) === $group));
            if (1 === \count($organizations)) {
                $this->titles[$key] = new Title(
                    name: $title->name,
                    kind: $title->kind,
                    organizationId: (string) $organizations[0],
                    vCardParams: $title->vCardParams,
                );
            }
        }
    }

    /**
     * LANG converts to a preferred language (RFC 9555, section 2.7.3).
     */
    private function language(PropertyReader $property): void
    {
        $language = trim($property->text());
        if (1 !== preg_match(Syntax::LANGUAGE_TAG, $language)) {
            $this->raw($property, \sprintf('"%s" is not a language tag', $language));

            return;
        }

        $common = $this->common($property);
        $this->preferredLanguages[$common->key] = new LanguagePref($language, $common->contexts, $common->pref, vCardParams: $common->vCardParams);
    }

    /**
     * RELATED converts to an entry of relatedTo, keyed by its value: the uid, URI or text
     * that identifies the related entity. Its TYPE values are the relation (RFC 9555,
     * section 2.9.5).
     */
    private function related(PropertyReader $property): void
    {
        $key = trim($property->text());
        if ('' === $key || isset($this->relatedTo[$key])) {
            $this->raw($property, '' === $key ? 'empty value' : 'another RELATED property has the same value');

            return;
        }

        $relation = $property->unreadTypes();
        foreach ($relation as $type) {
            $property->takeType($type);
        }

        $this->relatedTo[$key] = new Relation($relation, vCardParams: $property->unreadParameters());
    }

    /**
     * EXPERTISE, HOBBY and INTEREST convert to personal information, with LEVEL and INDEX
     * (RFC 9555, sections 2.3.10, 2.3.13 and 2.10).
     */
    private function personalInfo(PropertyReader $property): void
    {
        $level = $property->parameter('LEVEL');
        if (null !== $level) {
            $level = strtolower($level);
            $level = 'EXPERTISE' === $property->name ? (self::EXPERTISE_LEVELS[$level] ?? $level) : $level;
        }

        $listAs = null;
        if (null !== ($index = $property->peek('INDEX'))) {
            if (1 === preg_match('/^[1-9]\d*$/', $index)) {
                $listAs = (int) $property->parameter('INDEX');
            } else {
                $this->issues->add('', \sprintf('%s: INDEX "%s" is not a positive integer, kept it in vCardParams', $property->name, $index));
            }
        }

        $common = $this->common($property);
        $this->personalInfo[$common->key] = new PersonalInfo(
            kind: strtolower($property->name),
            value: $property->text(),
            level: $level,
            listAs: $listAs,
            label: $common->label,
            vCardParams: $common->vCardParams,
        );
    }

    /**
     * PHOTO, LOGO and SOUND convert to media, KEY to a crypto key, SOURCE and ORG-DIRECTORY
     * to directories, CALURI and FBURL to calendars, CALADRURI to a scheduling address
     * (RFC 9555, sections 2.4.3, 2.5.7, 2.9.2, 2.10.4, 2.11.7, 2.12.1 and 2.13).
     */
    private function resource(PropertyReader $property): void
    {
        $mediaType = $property->parameter('MEDIATYPE');
        $uri = $this->resourceUri($property, $mediaType);
        if (null === $uri) {
            $this->raw($property, 'not a URI');

            return;
        }

        $listAs = null;
        if ('ORG-DIRECTORY' === $property->name && null !== ($index = $property->peek('INDEX'))) {
            if (1 === preg_match('/^[1-9]\d*$/', $index)) {
                $listAs = (int) $property->parameter('INDEX');
            } else {
                $this->issues->add('', \sprintf('ORG-DIRECTORY: INDEX "%s" is not a positive integer, kept it in vCardParams', $index));
            }
        }

        $common = $this->common($property);
        $key = $common->key;
        match ($property->name) {
            'PHOTO', 'LOGO', 'SOUND' => $this->media[$key] = new Media($uri, strtolower($property->name), $mediaType, $common->contexts, $common->pref, $common->label, vCardParams: $common->vCardParams),
            'KEY' => $this->cryptoKeys[$key] = new CryptoKey($uri, null, $mediaType, $common->contexts, $common->pref, $common->label, vCardParams: $common->vCardParams),
            'SOURCE', 'ORG-DIRECTORY' => $this->directories[$key] = new Directory($uri, 'SOURCE' === $property->name ? Directory::KIND_ENTRY : Directory::KIND_DIRECTORY, $mediaType, $common->contexts, $common->pref, $common->label, $listAs, vCardParams: $common->vCardParams),
            'CALURI', 'FBURL' => $this->calendars[$key] = new Calendar($uri, 'CALURI' === $property->name ? Calendar::KIND_CALENDAR : Calendar::KIND_FREE_BUSY, $mediaType, $common->contexts, $common->pref, $common->label, vCardParams: $common->vCardParams),
            default => $this->schedulingAddresses[$key] = new SchedulingAddress($uri, $common->contexts, $common->pref, $common->label, vCardParams: $common->vCardParams),
        };
    }

    /**
     * The URI of a resource. vCard 3.0 and 2.1 embed media as binary, with their format as a
     * TYPE value: they become "data:" URIs. A format given for a URI becomes its media type.
     *
     * @param-out string|null $mediaType
     */
    private function resourceUri(PropertyReader $property, ?string &$mediaType): ?string
    {
        $format = null;
        if (\in_array($property->name, self::MEDIA, true)) {
            foreach ($property->unreadTypes() as $type) {
                if (null !== MediaTypes::fromFormat($type)) {
                    $format = $type;
                    $property->takeType($type);
                    break;
                }
            }
        }

        $encoding = strtolower($property->peek('ENCODING') ?? '');
        if ($property->property instanceof Binary || \in_array($encoding, ['b', 'base64'], true)) {
            // sabre/vobject workaround: sabre reads PHOTO and LOGO as binary, but KEY and SOUND
            // as text, still in base64.
            $bytes = $property->property instanceof Binary ? $property->property->getValue() : base64_decode(preg_replace('/\s+/', '', $property->text()) ?? '', true);
            $type = null === $format ? 'application/octet-stream' : MediaTypes::fromFormat($format);

            return \is_string($bytes) ? 'data:'.$type.';base64,'.base64_encode($bytes) : null;
        }

        if (null !== $format) {
            $mediaType ??= MediaTypes::fromFormat($format);
        }

        $uri = trim($property->text());

        return 1 === preg_match(Syntax::URI, $uri) ? $uri : null;
    }

    /**
     * GRAMGENDER converts to the grammatical gender (RFC 9555, section 2.5.4). Several of them
     * are versions in different languages, which convert to localizations: they are not
     * converted yet.
     */
    private function grammaticalGender(PropertyReader $property): void
    {
        $value = strtolower(trim($property->text()));
        if (null !== $this->grammaticalGender) {
            $this->raw($property, 'grammatical genders in other languages are not converted yet');
        } elseif (1 !== preg_match('/^[a-z0-9-]+$/', $value) || str_starts_with($value, 'x-')) {
            $this->raw($property, \sprintf('"%s" is not a valid GRAMGENDER value', $value));
        } else {
            $this->grammaticalGender = $property;
        }
    }

    private function pronoun(PropertyReader $property): void
    {
        $common = $this->common($property);
        $this->pronouns[$common->key] = new Pronouns($property->text(), $common->contexts, $common->pref, vCardParams: $common->vCardParams);
    }

    private function speakToAs(): ?SpeakToAs
    {
        if (null === $this->grammaticalGender && [] === $this->pronouns) {
            return null;
        }

        return new SpeakToAs(
            grammaticalGender: null === $this->grammaticalGender ? null : strtolower(trim($this->grammaticalGender->text())),
            pronouns: $this->pronouns,
            vCardParams: $this->grammaticalGender?->unreadParameters() ?? [],
        );
    }

    /**
     * BDAY, DEATHDATE and ANNIVERSARY convert to anniversaries, with the place of BIRTHPLACE
     * and DEATHPLACE (RFC 9555, section 2.5.1). A place without date cannot convert, as an
     * Anniversary needs a date.
     */
    private function anniversaries(): void
    {
        $seen = [];
        foreach ($this->dates as $property) {
            [$kind, $placeName] = match ($property->name) {
                'BDAY' => [Anniversary::KIND_BIRTH, 'BIRTHPLACE'],
                'DEATHDATE' => [Anniversary::KIND_DEATH, 'DEATHPLACE'],
                default => [Anniversary::KIND_WEDDING, null],
            };
            if (null !== $placeName && isset($seen[$property->name])) {
                $this->raw($property, \sprintf('more than one %s property', $property->name));
                continue;
            }

            $isText = 'text' === strtolower($property->peek('VALUE') ?? '');
            $date = $isText ? null : Dates::parse($property->property->getRawMimeDirValue());
            if (null === $date) {
                $this->raw($property, $isText ? 'a date as text has no JSContact counterpart' : 'not a date, nor a UTC date-time');
                continue;
            }

            $seen[$property->name] = true;

            $calendarScale = $date instanceof PartialDate ? $property->parameter('CALSCALE') : null;
            if (null !== $calendarScale) {
                $date = new PartialDate($date->year, $date->month, $date->day, strtolower($calendarScale));
            }

            $place = null;
            if (null !== $placeName && [] !== ($this->places[$placeName] ?? [])) {
                $place = $this->place(array_shift($this->places[$placeName]));
            }

            $common = $this->common($property);
            $this->anniversaries[$common->key] = new Anniversary(
                kind: $kind,
                date: $date,
                place: $place,
                vCardParams: $common->vCardParams,
            );
        }

        foreach ($this->places as $name => $places) {
            foreach ($places as $place) {
                $this->raw($place, \sprintf('no %s property to attach the place to', 'BIRTHPLACE' === $name ? 'BDAY' : 'DEATHDATE'));
            }
        }
    }

    /**
     * A BIRTHPLACE or DEATHPLACE: text is the full address, a "geo:" URI its coordinates.
     * Other URIs have no JSContact counterpart, and stay verbatim.
     */
    private function place(PropertyReader $property): ?Address
    {
        $isUri = 'uri' === strtolower($property->peek('VALUE') ?? '');
        $value = $property->text();
        if ($isUri && 0 !== stripos($value, 'geo:')) {
            $this->raw($property, 'only "geo:" URIs convert to a place');

            return null;
        }

        return new Address(
            coordinates: $isUri ? $value : null,
            full: $isUri ? null : $value,
            vCardParams: $property->unreadParameters(),
        );
    }

    /**
     * Reads what all entries have in common: key, contexts, preference and label. Must be
     * called after reading the property-specific parameters, as the unread ones go to
     * vCardParams.
     */
    private function common(PropertyReader $property, bool $isRepeated = false): Common
    {
        [$map, $prefix] = self::ENTRIES[$property->name];

        $propId = $isRepeated ? null : $property->parameter('PROP-ID');
        if (null !== $propId && Syntax::isId($propId) && !$this->hasKey($map, $propId)) {
            $key = $propId;
            $this->keys->skip($prefix);
        } else {
            if (null !== $propId) {
                $this->issues->add('', \sprintf('%s: PROP-ID "%s" is not a valid or unique Id, generated another key', $property->name, $propId));
            }

            $key = $this->keys->next($map, $prefix);
        }

        // Notes and titles have no contexts, notes, titles and organizations no preference:
        // their TYPE and PREF stay in vCardParams.
        $pref = null;
        $contexts = [];
        if (!\in_array($map, ['notes', 'titles', 'organizations', 'anniversaries', 'personalInfo'], true)) {
            $value = $property->parameter('PREF');
            if (null !== $value && 1 === preg_match('/^\d+$/', $value) && (int) $value >= 1 && (int) $value <= 100) {
                $pref = (int) $value;
            } elseif (null !== $value) {
                $this->issues->add('/'.$map.'/'.$key, \sprintf('PREF "%s" is not between 1 and 100, ignored it', $value));
            }

            if ($property->takeType('pref')) {
                $pref = 1; // vCard 3.0 and 2.1
            }
        }

        if (!\in_array($map, ['notes', 'titles', 'anniversaries', 'personalInfo'], true)) {
            $types = ['home' => 'private', 'work' => 'work'];
            if ('addresses' === $map) {
                $types += ['billing' => 'billing', 'delivery' => 'delivery'];
            }

            foreach ($types as $type => $context) {
                if ($property->takeType($type)) {
                    $contexts[] = $context;
                }
            }
        }

        $label = null;
        if (null !== $property->group && isset($this->labels[$property->group]) && \in_array($property->name, self::LABELED, true)) {
            $label = $this->labels[$property->group]->text();
            unset($this->labels[$property->group]);
        }

        return new Common(
            key: $key,
            contexts: $contexts,
            pref: $pref,
            label: $label,
            vCardName: \in_array($property->name, ['IMPP', 'SOCIALPROFILE'], true) ? strtolower($property->name) : null,
            vCardParams: $property->unreadParameters(),
        );
    }

    private function hasKey(string $map, string $key): bool
    {
        return match ($map) {
            'nicknames' => isset($this->nicknames[$key]),
            'emails' => isset($this->emails[$key]),
            'phones' => isset($this->phones[$key]),
            'addresses' => isset($this->addresses[$key]),
            'onlineServices' => isset($this->onlineServices[$key]),
            'links' => isset($this->links[$key]),
            'organizations' => isset($this->organizations[$key]),
            'titles' => isset($this->titles[$key]),
            'anniversaries' => isset($this->anniversaries[$key]),
            'pronouns' => isset($this->pronouns[$key]),
            'media' => isset($this->media[$key]),
            'cryptoKeys' => isset($this->cryptoKeys[$key]),
            'directories' => isset($this->directories[$key]),
            'calendars' => isset($this->calendars[$key]),
            'schedulingAddresses' => isset($this->schedulingAddresses[$key]),
            'preferredLanguages' => isset($this->preferredLanguages[$key]),
            'personalInfo' => isset($this->personalInfo[$key]),
            default => isset($this->notes[$key]),
        };
    }

    private function name(): ?Name
    {
        $main = array_shift($this->names);
        foreach ($this->names as $extra) {
            $this->raw($extra, 'more than one N property');
        }

        $full = $this->fullName(null !== $main);
        if (null === $main) {
            foreach ($this->phonetics as $phonetic) {
                $this->raw($phonetic, 'no N property relates to it by ALTID');
            }

            return null === $full ? null : new Name(full: $full->text(), vCardParams: $full->unreadParameters());
        }

        $structured = $main->structured($this->rawValue($main));
        $values = Components::fromName($structured);
        [$components, $isOrdered, $defaultSeparator] = $this->ordered($main, $values, $structured, Components::NAME_KINDS, '/name');
        $sortAs = $this->sortAs($main, $values);
        [$phonetics, $phoneticSystem, $phoneticScript] = $this->phonetic($main, $components);

        return new Name(
            components: array_map(
                static fn (array $component, int $index): NameComponent => new NameComponent($component['kind'], $component['value'], $phonetics[$index] ?? null),
                $components,
                array_keys($components),
            ),
            isOrdered: $isOrdered,
            defaultSeparator: $defaultSeparator,
            full: $full?->text(),
            sortAs: $sortAs,
            phoneticScript: $phoneticScript,
            phoneticSystem: $phoneticSystem,
            vCardParams: $main->unreadParameters(),
        );
    }

    /**
     * Picks the FN property that converts to the full name (RFC 9555, section 2.5.2): the
     * one without LANGUAGE that has the least parameters. Others are kept verbatim.
     */
    private function fullName(bool $hasName): ?PropertyReader
    {
        $candidates = $this->fullNames;
        usort($candidates, static fn (PropertyReader $a, PropertyReader $b): int => [$a->has('LANGUAGE'), \count($a->property->parameters())] <=> [$b->has('LANGUAGE'), \count($b->property->parameters())]);

        $chosen = null;
        foreach ($candidates as $candidate) {
            // An empty FN is what converting a Card without name gives (RFC 9555, section 3.1).
            if (null === $chosen && '' === trim($candidate->text()) && [] === $candidate->unreadParameters()) {
                continue;
            }

            $isDerived = 'true' === strtolower($candidate->parameter('DERIVED') ?? '');
            if (null === $chosen && $isDerived && $hasName && [] === $candidate->unreadParameters()) {
                continue; // Derived from N: it is derived again when converting back.
            }

            // When there is an N property, the Name's vCardParams hold its parameters.
            if (null === $chosen && (!$hasName || [] === $candidate->unreadParameters())) {
                $chosen = $candidate;
            } else {
                $this->raw($candidate);
            }
        }

        return $chosen;
    }

    /**
     * Name or Address components, ordered as JSCOMPS says if it is valid.
     *
     * @param list<array{kind: string, value: string, position: array{int, int}}> $values
     * @param list<list<string>>                                                  $structured
     * @param list<string>                                                        $kinds
     *
     * @return array{list<array{kind: string, value: string, position: array{int, int}|null}>, bool, string|null} Components, isOrdered, defaultSeparator
     */
    private function ordered(PropertyReader $property, array $values, array $structured, array $kinds, string $path): array
    {
        $jsComps = $property->parameter('JSCOMPS');
        $order = null === $jsComps ? null : Components::order($jsComps, $values, $structured, $kinds);
        if (null === $order) {
            if (null !== $jsComps) {
                $this->issues->add($path, \sprintf('JSCOMPS "%s" does not match the components, ignored it', $jsComps));
            }

            return [$values, false, null];
        }

        return [$order['components'], true, $order['defaultSeparator']];
    }

    /**
     * @param list<array{kind: string, value: string, position: array{int, int}}> $values
     *
     * @return array<string, string>
     */
    private function sortAs(PropertyReader $property, array $values): array
    {
        $sortAs = [];
        foreach (explode(',', $property->peek('SORT-AS') ?? '') as $index => $value) {
            if ('' !== $value && isset(Components::NAME_KINDS[$index])) {
                $sortAs[Components::NAME_KINDS[$index]] = $value;
            }
        }

        if ([] === $sortAs) {
            return [];
        }

        if ([] !== array_diff(array_keys($sortAs), array_column($values, 'kind'))) {
            $this->issues->add('/name/sortAs', 'SORT-AS sorts by a component the name does not have, kept it in vCardParams');

            return [];
        }

        $property->parameter('SORT-AS');

        return $sortAs;
    }

    /**
     * Reads the phonetic N property related to the name by ALTID (RFC 9555, section 2.3.15).
     *
     * @param list<array{kind: string, value: string, position: array{int, int}|null}> $components
     *
     * @return array{array<int, string>, string|null, string|null} Phonetic value of each component, phoneticSystem, phoneticScript
     */
    private function phonetic(PropertyReader $main, array $components): array
    {
        $altId = $main->peek('ALTID');
        $result = [[], null, null];
        $found = false;
        foreach ($this->phonetics as $phonetic) {
            $isRelated = null !== $altId && $altId === $phonetic->peek('ALTID');
            if (!$isRelated || $found || $phonetic->has('LANGUAGE')) {
                $this->raw($phonetic, $isRelated ? 'phonetic names in another language are not converted yet' : 'no N property relates to it by ALTID');
                continue;
            }

            $found = true;
            $main->parameter('ALTID');
            $phonetic->parameter('ALTID');
            $system = strtolower($phonetic->parameter('PHONETIC') ?? '');
            $values = $phonetic->structured($this->rawValue($phonetic));
            foreach ($components as $index => $component) {
                $value = null === $component['position'] ? null : ($values[$component['position'][0]][$component['position'][1]] ?? null);
                if (null !== $value && '' !== $value) {
                    $result[0][$index] = $value;
                }
            }

            $result[1] = 'script' === $system ? null : $system;
            $result[2] = $phonetic->parameter('SCRIPT');

            if ([] !== $phonetic->unreadParameters()) {
                $this->issues->add('/name', \sprintf('ignored the parameters %s of the phonetic N property', implode(', ', $phonetic->unreadParameterNames())));
            }
        }

        return $result;
    }

    /**
     * Applies the JSPROP properties (RFC 9555, section 3.2.1) once everything else is
     * converted. An invalid PatchObject is not applied: the JSPROP properties are kept.
     *
     * @return Result<Card>
     */
    private function patch(Card $card): Result
    {
        $patches = [];
        $error = null;
        foreach ($this->jsProps as $jsProp) {
            $pointer = $jsProp->parameter('JSPTR');
            try {
                $value = json_decode($jsProp->text(), false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $error ??= \sprintf('the value of JSPROP "%s" is not JSON', $pointer);
                continue;
            }

            if (null === $pointer || \array_key_exists($pointer, $patches)) {
                $error ??= 'a JSPROP property has no JSPTR parameter, or a repeated one';
                continue;
            }

            $patches[$pointer] = $value;
        }

        $json = new JsonEncoder(validate: false)->normalize($card);
        $error ??= Patch::apply($json, $patches);
        if (null !== $error) {
            foreach ($this->jsProps as $jsProp) {
                $this->raw($jsProp);
            }

            $this->issues->add('', 'kept the JSPROP properties verbatim: '.$error);
            $card = new Card(
                uid: $card->uid, prodId: $card->prodId, created: $card->created, updated: $card->updated,
                kind: $card->kind, language: $card->language, members: $card->members, name: $card->name,
                speakToAs: $card->speakToAs,
                nicknames: $card->nicknames, organizations: $card->organizations, titles: $card->titles,
                emails: $card->emails, phones: $card->phones,
                addresses: $card->addresses, onlineServices: $card->onlineServices, links: $card->links,
                notes: $card->notes, anniversaries: $card->anniversaries, media: $card->media,
                cryptoKeys: $card->cryptoKeys, directories: $card->directories, calendars: $card->calendars,
                schedulingAddresses: $card->schedulingAddresses, preferredLanguages: $card->preferredLanguages,
                relatedTo: $card->relatedTo, personalInfo: $card->personalInfo, keywords: $card->keywords, vCardProps: $this->vCardProps,
            );

            return new Result($card, [...$this->issues->all(), ...$this->validator->validate($card)]);
        }

        $result = new JsonDecoder(validator: $this->validator)->decode(json_encode($json, \JSON_THROW_ON_ERROR));

        return new Result($result->value, [...$this->issues->all(), ...$result->issues]);
    }

    private function raw(PropertyReader $property, ?string $reason = null): void
    {
        // It still takes its position, so that the keys of the next properties do not depend
        // on whether this one converts.
        if (isset(self::ENTRIES[$property->name])) {
            $this->keys->skip(self::ENTRIES[$property->name][1]);
        }

        $index = \count($this->vCardProps);
        $this->vCardProps[] = $property->toVCardProperty($this->rawValue($property));
        if (null !== $reason) {
            $this->issues->add('/vCardProps/'.$index, \sprintf('kept %s verbatim: %s', $property->name, $reason));
        }
    }

    private function rawValue(PropertyReader $property): ?string
    {
        return $this->raw[spl_object_id($property)] ?? null;
    }
}
