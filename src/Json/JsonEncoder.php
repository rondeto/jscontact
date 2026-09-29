<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Json;

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
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\Validation\Syntax;

/**
 * Writes a Card as JSContact version 2.0 JSON (RFC 9553, as updated by RFC 9982).
 *
 * Writing is strict: a Card that breaks the specification is refused. Nested objects are
 * written without their optional @var property.
 */
final readonly class JsonEncoder
{
    public const string VERSION = '2.0';

    public function __construct(
        private bool $validate = true,
        private CardValidator $validator = new CardValidator(),
    ) {
    }

    /**
     * @throws InvalidCardException when validation is on and the Card is invalid
     */
    public function encode(Card $card, bool $pretty = false): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

        return json_encode($this->normalize($card), $pretty ? $flags | \JSON_PRETTY_PRINT : $flags);
    }

    /**
     * The Card as the value json_decode() would return for its JSON form.
     *
     * @throws InvalidCardException when validation is on and the Card is invalid
     */
    public function normalize(Card $card): \stdClass
    {
        $violations = $this->validate ? $this->validator->validate($card) : [];
        if ([] !== $violations) {
            throw new InvalidCardException($violations);
        }

        return $this->object([
            '@type' => 'Card',
            'version' => self::VERSION,
            'uid' => $card->uid,
            'prodId' => $card->prodId,
            'created' => $this->dateTime($card->created),
            'updated' => $this->dateTime($card->updated),
            'kind' => $card->kind,
            'language' => $card->language,
            'members' => $this->set($card->members),
            'name' => null === $card->name ? null : $this->name($card->name),
            'nicknames' => $this->map($card->nicknames, $this->nickname(...)),
            'emails' => $this->map($card->emails, $this->email(...)),
            'phones' => $this->map($card->phones, $this->phone(...)),
            'addresses' => $this->map($card->addresses, $this->address(...)),
            'onlineServices' => $this->map($card->onlineServices, $this->onlineService(...)),
            'links' => $this->map($card->links, $this->link(...)),
            'notes' => $this->map($card->notes, $this->note(...)),
            'keywords' => $this->set($card->keywords),
        ], $card->extra);
    }

    private function name(Name $name): \stdClass
    {
        return $this->object([
            'components' => $this->list($name->components, $this->nameComponent(...)),
            'isOrdered' => $name->isOrdered ?: null,
            'defaultSeparator' => $name->defaultSeparator,
            'full' => $name->full,
            'sortAs' => [] === $name->sortAs ? null : $this->object($name->sortAs),
            'phoneticScript' => $name->phoneticScript,
            'phoneticSystem' => $name->phoneticSystem,
        ], $name->extra);
    }

    private function nameComponent(NameComponent $component): \stdClass
    {
        return $this->object([
            'kind' => $component->kind,
            'value' => $component->value,
            'phonetic' => $component->phonetic,
        ], $component->extra);
    }

    private function nickname(Nickname $nickname): \stdClass
    {
        return $this->object([
            'name' => $nickname->name,
            'contexts' => $this->set($nickname->contexts),
            'pref' => $nickname->pref,
        ], $nickname->extra);
    }

    private function email(EmailAddress $email): \stdClass
    {
        return $this->object([
            'address' => $email->address,
            'contexts' => $this->set($email->contexts),
            'pref' => $email->pref,
            'label' => $email->label,
        ], $email->extra);
    }

    private function phone(Phone $phone): \stdClass
    {
        return $this->object([
            'number' => $phone->number,
            'features' => $this->set($phone->features),
            'contexts' => $this->set($phone->contexts),
            'pref' => $phone->pref,
            'label' => $phone->label,
        ], $phone->extra);
    }

    private function address(Address $address): \stdClass
    {
        return $this->object([
            'components' => $this->list($address->components, $this->addressComponent(...)),
            'isOrdered' => $address->isOrdered ?: null,
            'countryCode' => $address->countryCode,
            'coordinates' => $address->coordinates,
            'timeZone' => $address->timeZone,
            'contexts' => $this->set($address->contexts),
            'full' => $address->full,
            'defaultSeparator' => $address->defaultSeparator,
            'pref' => $address->pref,
            'phoneticScript' => $address->phoneticScript,
            'phoneticSystem' => $address->phoneticSystem,
        ], $address->extra);
    }

    private function addressComponent(AddressComponent $component): \stdClass
    {
        return $this->object([
            'kind' => $component->kind,
            'value' => $component->value,
            'phonetic' => $component->phonetic,
        ], $component->extra);
    }

    private function onlineService(OnlineService $service): \stdClass
    {
        return $this->object([
            'service' => $service->service,
            'uri' => $service->uri,
            'user' => $service->user,
            'contexts' => $this->set($service->contexts),
            'pref' => $service->pref,
            'label' => $service->label,
        ], $service->extra);
    }

    private function link(Link $link): \stdClass
    {
        return $this->object([
            'kind' => $link->kind,
            'uri' => $link->uri,
            'mediaType' => $link->mediaType,
            'contexts' => $this->set($link->contexts),
            'pref' => $link->pref,
            'label' => $link->label,
        ], $link->extra);
    }

    private function note(Note $note): \stdClass
    {
        return $this->object([
            'note' => $note->note,
            'created' => $this->dateTime($note->created),
            'author' => null === $note->author ? null : $this->author($note->author),
        ], $note->extra);
    }

    private function author(Author $author): \stdClass
    {
        return $this->object([
            'name' => $author->name,
            'uri' => $author->uri,
        ], $author->extra);
    }

    /**
     * Skips null values, so that unset properties are left out.
     *
     * @param array<array-key, mixed> $properties
     * @param array<array-key, mixed> $extra
     */
    private function object(array $properties, array $extra = []): \stdClass
    {
        $object = new \stdClass();
        foreach ($properties + $extra as $name => $value) {
            if (null !== $value) {
                $object->{$name} = $value;
            }
        }

        return $object;
    }

    /**
     * @template T of object
     *
     * @param array<array-key, T>    $map
     * @param callable(T): \stdClass $normalize
     */
    private function map(array $map, callable $normalize): ?\stdClass
    {
        if ([] === $map) {
            return null;
        }

        // An object, even with numeric keys, which PHP would otherwise encode as an array.
        $object = new \stdClass();
        foreach ($map as $id => $value) {
            $object->{$id} = $normalize($value);
        }

        return $object;
    }

    /**
     * @template T of object
     *
     * @param list<T>                $list
     * @param callable(T): \stdClass $normalize
     *
     * @return list<\stdClass>|null
     */
    private function list(array $list, callable $normalize): ?array
    {
        return [] === $list ? null : array_map($normalize, $list);
    }

    /**
     * @param list<string> $set
     */
    private function set(array $set): ?\stdClass
    {
        if ([] === $set) {
            return null;
        }

        $object = new \stdClass();
        foreach ($set as $key) {
            $object->{$key} = true;
        }

        return $object;
    }

    private function dateTime(?\DateTimeImmutable $dateTime): ?string
    {
        return null === $dateTime ? null : Syntax::formatUtcDateTime($dateTime);
    }
}
