<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

final class CardValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{Card, list<string>}>
     */
    public static function invalidCards(): iterable
    {
        yield 'empty strings' => [
            new Card(uid: '', prodId: '', language: ''),
            ['/prodId: must not be empty', '/uid: must not be empty', '/language: must not be empty'],
        ];
        yield 'kind case variant' => [new Card(kind: 'Group'), ['/kind: "Group" must be written "group"']];
        yield 'members outside a group' => [
            new Card(kind: Card::KIND_ORG, members: ['urn:uuid:1']),
            ['/members: members can only be set when kind is "group"'],
        ];
        yield 'invalid Id' => [
            new Card(nicknames: ['a b' => new Nickname('Jo')]),
            ['/nicknames/a b: not a valid Id'],
        ];
        yield 'pref out of range' => [
            new Card(phones: ['p1' => new Phone('tel:+1', pref: 101)]),
            ['/phones/p1/pref: must be between 1 and 100'],
        ];
        yield 'email address' => [
            new Card(emails: ['e1' => new EmailAddress('jane.example.com')]),
            ['/emails/e1/address: "jane.example.com" is not an email address'],
        ];
        yield 'context case variant' => [
            new Card(emails: ['e1' => new EmailAddress('a@example.com', contexts: ['Work'])]),
            ['/emails/e1/contexts/Work: "Work" must be written "work"'],
        ];
        yield 'empty name' => [new Card(name: new Name()), ['/name: a name needs components, full, or both']];
        yield 'separator in unordered name' => [
            new Card(name: new Name(components: [new NameComponent('given', 'A'), new NameComponent('separator', '-')], defaultSeparator: ' ')),
            ['/name/components/1: separators are only allowed when isOrdered is true', '/name/defaultSeparator: defaultSeparator requires ordered components'],
        ];
        yield 'only separators' => [
            new Card(name: new Name(components: [new NameComponent('separator', '-')], isOrdered: true)),
            ['/name/components: at least one component must not be a separator'],
        ];
        yield 'consecutive separators' => [
            new Card(name: new Name(components: [new NameComponent('given', 'A'), new NameComponent('separator', '-'), new NameComponent('separator', '-'), new NameComponent('surname', 'B')], isOrdered: true)),
            ['/name/components/2: two separators cannot follow each other'],
        ];
        yield 'sortAs' => [
            new Card(name: new Name(components: [new NameComponent('given', 'A')], sortAs: ['surname' => 'B'])),
            ['/name/sortAs/surname: no name component has this kind'],
        ];
        yield 'phonetic without system' => [
            new Card(name: new Name(components: [new NameComponent('given', 'A', 'a')])),
            ['/name/components/0/phonetic: phonetic requires phoneticSystem or phoneticScript'],
        ];
        yield 'empty address' => [
            new Card(addresses: ['a1' => new Address()]),
            ['/addresses/a1: an address needs at least one of components, coordinates, countryCode, full or timeZone'],
        ];
        yield 'address fields' => [
            new Card(addresses: ['a1' => new Address(components: [new AddressComponent('Locality', 'Paris')], countryCode: 'FRA', coordinates: '48.8,2.3', contexts: ['billing'])]),
            ['/addresses/a1/components/0/kind: "Locality" must be written "locality"', '/addresses/a1/countryCode: not an ISO 3166-1 alpha-2 code', '/addresses/a1/coordinates: not a "geo:" URI'],
        ];
        yield 'billing is only for addresses' => [
            new Card(emails: ['e1' => new EmailAddress('a@example.com', contexts: ['billing'])]),
            [],
        ];
        yield 'online service' => [
            new Card(onlineServices: ['o1' => new OnlineService(service: 'GitHub'), 'o2' => new OnlineService(uri: 'github.com/jane')]),
            ['/onlineServices/o1: an online service needs a uri, a user, or both', '/onlineServices/o2/uri: "github.com/jane" is not a URI'],
        ];
        yield 'link' => [new Card(links: ['l1' => new Link('example.com')]), ['/links/l1/uri: "example.com" is not a URI']];
        yield 'author' => [
            new Card(notes: ['n1' => new Note('Hi', author: new Author())]),
            ['/notes/n1/author: an author needs at least one property'],
        ];
        yield 'extra' => [
            new Card(extra: ['uid' => 'x', 'Organizations' => [], 'extra' => 1, 'bad name' => 1, 'organizations' => [], 'example.com:x' => 1]),
            ['/uid: this property is modeled: set it on the object, not in extra', '/Organizations: must be written "organizations"', '/extra: "extra" is a reserved property name', '/bad name: not a valid property name'],
        ];
        yield 'nested extra' => [
            new Card(emails: ['e1' => new EmailAddress('a@example.com', extra: ['Label' => 'x', 'vCardParams' => []])]),
            ['/emails/e1/Label: this property is modeled: set it on the object, not in extra'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidCards')]
    public function testItReportsViolations(Card $card, array $expected): void
    {
        self::assertSame($expected, array_map(strval(...), new CardValidator()->validate($card)));
    }

    public function testAValidCardHasNoViolation(): void
    {
        $card = new Card(
            uid: 'urn:uuid:1',
            kind: Card::KIND_GROUP,
            members: ['urn:uuid:2'],
            name: new Name(components: [new NameComponent('given', 'Jane'), new NameComponent('surname', 'Doe')], isOrdered: true, defaultSeparator: ' ', sortAs: ['surname' => 'Doe']),
            emails: ['e1' => new EmailAddress('jane@example.com', contexts: ['work'], pref: 1)],
            addresses: ['a1' => new Address(countryCode: 'fr', contexts: ['billing'])],
        );

        self::assertSame([], new CardValidator()->validate($card));
    }
}
