<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Json;

use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Conversion\WarningCollector;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Author;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Validation\CardValidator;
use Rondeto\JSContact\Validation\Registry;

/**
 * Reads a JSContact Card from JSON (RFC 9553, as updated by RFC 9982).
 *
 * Reading is lenient: a value that breaks the specification is skipped or corrected, and
 * reported as a warning. The remaining rule violations (see CardValidator) are reported
 * as warnings too, but the values are kept: fix them before encoding the Card again.
 */
final readonly class JsonDecoder
{
    private const array SUPPORTED_MAJOR_VERSIONS = ['1', '2'];

    public function __construct(
        private CardValidator $validator = new CardValidator(),
    ) {
    }

    /**
     * @return Result<Card>
     *
     * @throws DecodingException when the input is not JSON, or not a Card object
     */
    public function decode(string $json): Result
    {
        try {
            $data = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DecodingException('The input is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (!$data instanceof \stdClass) {
            throw new DecodingException('The input is not a JSON object.');
        }

        $warnings = new WarningCollector();
        $object = new JsonObject($data, '', $warnings);

        if (!$object->has('@type')) {
            $object->warn('@type', 'missing mandatory property, read the object as a Card');
        } elseif (!$object->isOfType('Card')) {
            throw new DecodingException('The input is not a JSContact Card.');
        }

        $card = $this->card($object);

        foreach ($this->validator->validate($card) as $violation) {
            $warnings->add($violation->path, $violation->message);
        }

        return new Result($card, $warnings->all());
    }

    private function card(JsonObject $object): Card
    {
        $version = $object->string('version');
        $uid = $object->string('uid');
        if (null === $version) {
            $object->warn('version', 'missing mandatory property, read the Card as version 2.0');
        } elseif (1 !== preg_match('/^(\d+)\.\d+$/', $version, $matches) || !\in_array($matches[1], self::SUPPORTED_MAJOR_VERSIONS, true)) {
            $object->warn('version', \sprintf('unsupported version "%s", read the Card as version 2.0', $version));
        } elseif ('1' === $matches[1] && null === $uid) {
            $object->warn('uid', 'missing mandatory property in a version 1.0 Card');
        }

        $name = $object->object('name');

        return new Card(
            uid: $uid,
            prodId: $object->string('prodId'),
            created: $object->dateTime('created'),
            updated: $object->dateTime('updated'),
            kind: $object->enum('kind', Registry::CARD_KINDS),
            language: $object->string('language'),
            members: $object->set('members'),
            name: null === $name ? null : $this->name($name),
            nicknames: $this->map($object, 'nicknames', 'Nickname', $this->nickname(...)),
            emails: $this->map($object, 'emails', 'EmailAddress', $this->email(...)),
            phones: $this->map($object, 'phones', 'Phone', $this->phone(...)),
            addresses: $this->map($object, 'addresses', 'Address', $this->address(...)),
            onlineServices: $this->map($object, 'onlineServices', 'OnlineService', $this->onlineService(...)),
            links: $this->map($object, 'links', 'Link', $this->link(...)),
            notes: $this->map($object, 'notes', 'Note', $this->note(...)),
            keywords: $object->set('keywords'),
            extra: $object->extra(Registry::UNMODELED_CARD_PROPERTIES),
        );
    }

    private function name(JsonObject $object): ?Name
    {
        if (!$object->isOfType('Name')) {
            return null;
        }

        return new Name(
            components: $this->components($object, 'NameComponent', Registry::NAME_COMPONENT_KINDS, static fn (string $kind, string $value, ?string $phonetic, array $extra): NameComponent => new NameComponent($kind, $value, $phonetic, $extra)),
            isOrdered: $object->bool('isOrdered') ?? false,
            defaultSeparator: $object->string('defaultSeparator'),
            full: $object->string('full'),
            sortAs: $object->stringMap('sortAs'),
            phoneticScript: $object->string('phoneticScript'),
            phoneticSystem: $object->enum('phoneticSystem', Registry::PHONETIC_SYSTEMS),
            extra: $object->extra(),
        );
    }

    private function nickname(JsonObject $object): ?Nickname
    {
        $name = $object->requiredString('name');
        if (null === $name) {
            return null;
        }

        return new Nickname(
            name: $name,
            contexts: $object->set('contexts', Registry::CONTEXTS),
            pref: $object->pref(),
            extra: $object->extra(),
        );
    }

    private function email(JsonObject $object): ?EmailAddress
    {
        $address = $object->requiredString('address');
        if (null === $address) {
            return null;
        }

        return new EmailAddress(
            address: $address,
            contexts: $object->set('contexts', Registry::CONTEXTS),
            pref: $object->pref(),
            label: $object->string('label'),
            extra: $object->extra(),
        );
    }

    private function phone(JsonObject $object): ?Phone
    {
        $number = $object->requiredString('number');
        if (null === $number) {
            return null;
        }

        return new Phone(
            number: $number,
            features: $object->set('features', Registry::PHONE_FEATURES),
            contexts: $object->set('contexts', Registry::CONTEXTS),
            pref: $object->pref(),
            label: $object->string('label'),
            extra: $object->extra(),
        );
    }

    private function address(JsonObject $object): Address
    {
        return new Address(
            components: $this->components($object, 'AddressComponent', Registry::ADDRESS_COMPONENT_KINDS, static fn (string $kind, string $value, ?string $phonetic, array $extra): AddressComponent => new AddressComponent($kind, $value, $phonetic, $extra)),
            isOrdered: $object->bool('isOrdered') ?? false,
            countryCode: $object->string('countryCode'),
            coordinates: $object->string('coordinates'),
            timeZone: $object->string('timeZone'),
            contexts: $object->set('contexts', Registry::ADDRESS_CONTEXTS),
            full: $object->string('full'),
            defaultSeparator: $object->string('defaultSeparator'),
            pref: $object->pref(),
            phoneticScript: $object->string('phoneticScript'),
            phoneticSystem: $object->enum('phoneticSystem', Registry::PHONETIC_SYSTEMS),
            extra: $object->extra(),
        );
    }

    private function onlineService(JsonObject $object): OnlineService
    {
        return new OnlineService(
            service: $object->string('service'),
            uri: $object->string('uri'),
            user: $object->string('user'),
            contexts: $object->set('contexts', Registry::CONTEXTS),
            pref: $object->pref(),
            label: $object->string('label'),
            extra: $object->extra(),
        );
    }

    private function link(JsonObject $object): ?Link
    {
        $uri = $object->requiredString('uri');
        if (null === $uri) {
            return null;
        }

        return new Link(
            uri: $uri,
            kind: $object->enum('kind', Registry::LINK_KINDS),
            mediaType: $object->string('mediaType'),
            contexts: $object->set('contexts', Registry::CONTEXTS),
            pref: $object->pref(),
            label: $object->string('label'),
            extra: $object->extra(),
        );
    }

    private function note(JsonObject $object): ?Note
    {
        $note = $object->requiredString('note');
        if (null === $note) {
            return null;
        }

        $author = $object->object('author');

        return new Note(
            note: $note,
            created: $object->dateTime('created'),
            author: null === $author || !$author->isOfType('Author') ? null : new Author(
                name: $author->string('name'),
                uri: $author->string('uri'),
                extra: $author->extra(),
            ),
            extra: $object->extra(),
        );
    }

    /**
     * @template T of object
     *
     * @param callable(JsonObject): (T|null) $read
     *
     * @return array<array-key, T>
     */
    private function map(JsonObject $card, string $name, string $type, callable $read): array
    {
        $map = [];
        foreach ($card->objectMap($name) as $id => $object) {
            $value = $object->isOfType($type) ? $read($object) : null;
            if (null === $value) {
                $card->warn($name.'/'.$id, 'ignored the entry');
            } else {
                $map[$id] = $value;
            }
        }

        return $map;
    }

    /**
     * @template T of NameComponent|AddressComponent
     *
     * @param list<string>                                                      $kinds
     * @param callable(string, string, string|null, array<array-key, mixed>): T $create
     *
     * @return list<T>
     */
    private function components(JsonObject $parent, string $type, array $kinds, callable $create): array
    {
        $components = [];
        foreach ($parent->objectList('components') as $index => $object) {
            if (!$object->isOfType($type)) {
                $parent->warn('components/'.$index, 'ignored the component');
                continue;
            }

            if (!$object->has('kind')) {
                $object->warn('kind', 'missing mandatory property');
            }

            $kind = $object->enum('kind', $kinds);
            $value = $object->requiredString('value');
            if (null === $kind || null === $value) {
                $parent->warn('components/'.$index, 'ignored the component');
                continue;
            }

            $components[] = $create($kind, $value, $object->string('phonetic'), $object->extra());
        }

        return $components;
    }
}
