<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Author;
use Rondeto\JSContact\Model\Calendar;
use Rondeto\JSContact\Model\Card;
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
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\PatchObject;
use Rondeto\JSContact\Model\PersonalInfo;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Validation\CardValidator;
use Symfony\Component\Validator\Validation;

final class CardValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{Card, list<string>}>
     */
    public static function invalidCards(): iterable
    {
        yield 'empty strings' => [
            new Card(uid: '', prodId: '', language: ''),
            ['/uid: must not be empty', '/prodId: must not be empty', '/language: must not be empty'],
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
        yield 'organization without name nor units' => [
            new Card(organizations: ['o1' => new Organization(sortAs: 'ACME')]),
            ['/organizations/o1: an organization needs a name, units, or both'],
        ];
        yield 'title' => [
            new Card(titles: ['t1' => new Title('Boss', 'Role', 'o1'), 't2' => new Title('', organizationId: 'not valid')]),
            ['/titles/t1/organizationId: no organization has this Id', '/titles/t2/organizationId: no organization has this Id', '/titles/t1/kind: "Role" must be written "role"', '/titles/t2/name: must not be empty', '/titles/t2/organizationId: not a valid Id'],
        ];
        yield 'partial dates' => [
            new Card(anniversaries: [
                'a1' => new Anniversary('Birth', new PartialDate(day: 3)),
                'a2' => new Anniversary('death', new PartialDate(month: 2)),
                'a3' => new Anniversary('wedding', new PartialDate(2023, 2, 29, 'Hebrew')),
                'a4' => new Anniversary('wedding', new PartialDate(month: 2, day: 29)),
                'a5' => new Anniversary('wedding', new PartialDate(2000, 13)),
            ]),
            [
                '/anniversaries/a1/kind: "Birth" must be written "birth"',
                '/anniversaries/a1/date: a partial date needs a year, a month, or both',
                '/anniversaries/a2/date/month: a month needs a year or a day',
                '/anniversaries/a3/date/day: this month has no such day',
                '/anniversaries/a3/date/calendarScale: must be lowercase',
                '/anniversaries/a5/date/month: must be between 1 and 12',
            ],
        ];
        yield 'speakToAs' => [
            new Card(speakToAs: new SpeakToAs('Neuter', ['p1' => new Pronouns('', pref: 0)])),
            ['/speakToAs/grammaticalGender: "Neuter" must be written "neuter"', '/speakToAs/pronouns/p1/pronouns: must not be empty', '/speakToAs/pronouns/p1/pref: must be between 1 and 100'],
        ];
        yield 'empty speakToAs' => [new Card(speakToAs: new SpeakToAs()), ['/speakToAs: needs a grammatical gender, pronouns, or both']];
        yield 'resources' => [
            new Card(
                media: ['m1' => new Media('photo.jpg', 'Photo', 'jpeg')],
                directories: ['d1' => new Directory('ldap://x', 'directory', listAs: 0)],
                calendars: ['c1' => new Calendar('https://x', 'freebusy')],
            ),
            [
                '/media/m1/uri: "photo.jpg" is not a URI',
                '/media/m1/kind: "Photo" must be written "photo"',
                '/media/m1/mediaType: not a media type',
                '/directories/d1/listAs: must be greater than 0',
                '/calendars/c1/kind: "freebusy" must be written "freeBusy"',
            ],
        ];
        yield 'languages, relations and personal information' => [
            new Card(
                preferredLanguages: ['l1' => new LanguagePref('french!')],
                relatedTo: ['' => new Relation(['Friend'])],
                personalInfo: ['p1' => new PersonalInfo('Hobby', 'chess', 'High', 0)],
            ),
            [
                '/preferredLanguages/l1/language: "french!" is not a language tag',
                '/relatedTo/: a related Card needs a uid',
                '/relatedTo//relation/Friend: "Friend" must be written "friend"',
                '/personalInfo/p1/kind: "Hobby" must be written "hobby"',
                '/personalInfo/p1/level: "High" must be written "high"',
                '/personalInfo/p1/listAs: must be greater than 0',
            ],
        ];
        yield 'localizations' => [
            new Card(
                name: new Name([new NameComponent('given', 'Jane')], isOrdered: true),
                emails: ['e1' => new EmailAddress('a@example.com')],
                localizations: [
                    'not a tag' => new PatchObject([]),
                    'fr' => new PatchObject(['localizations/de' => []]),
                    'de' => new PatchObject(['name/components/-' => []]),
                    'es' => new PatchObject(['name/components/1/value' => 'Juana']),
                    'it' => new PatchObject(['name/components/0' => null]),
                    'nl' => new PatchObject(['name' => ['full' => 'Jane'], 'name/full' => 'Jane']),
                    'pt' => new PatchObject(['emails/e1/pref' => 0]),
                ],
            ),
            [
                '/localizations/not a tag: not a language tag',
                '/localizations/fr/localizations~1de: a localization cannot patch localizations',
                '/localizations/de: "name/components/-" is not a valid path',
                '/localizations/es: "name/components/1/value" points into a value that does not exist',
                '/localizations/it: "name/components/0" cannot remove an array element',
                '/localizations/nl: "name" is a prefix of "name/full"',
                '/localizations/pt/emails~1e1~1pref: gives an invalid Card: /emails/e1/pref: expected an integer from 1 to 100, ignored the value',
            ],
        ];
        yield 'extra' => [
            new Card(extra: ['uid' => 'x', 'Localizations' => [], 'extra' => 1, 'bad name' => 1, 'example.com:x' => 1]),
            ['/uid: this property is modeled: set it on the object, not in extra', '/Localizations: this property is modeled: set it on the object, not in extra', '/extra: "extra" is a reserved property name', '/bad name: not a valid property name'],
        ];
        yield 'nested extra' => [
            new Card(emails: ['e1' => new EmailAddress('a@example.com', extra: ['Label' => 'x', 'example.com:x' => []])]),
            ['/emails/e1/Label: this property is modeled: set it on the object, not in extra'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidCards')]
    public function testItReportsIssues(Card $card, array $expected): void
    {
        self::assertSame($expected, array_map(strval(...), new CardValidator()->validate($card)));
    }

    public function testAValidCardHasNoIssue(): void
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

    public function testAnySymfonyValidatorCanValidateACard(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validator->validate(new Card(emails: ['e1' => new EmailAddress('a@example.com', pref: 0)]));

        self::assertCount(1, $violations);
        self::assertSame('emails[e1].pref', $violations[0]?->getPropertyPath());
    }
}
