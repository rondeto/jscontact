<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Json;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Author;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Timestamp;
use Rondeto\JSContact\Model\Title;

/**
 * The RFC 9553 figures of the modeled properties, read into the typed model.
 */
final class Rfc9553TypedFiguresTest extends TestCase
{
    public function testFigure1PhoneticName(): void
    {
        self::assertEquals(new Name(
            components: [
                new NameComponent('given', 'John', '/ˈdʒɑːn/'),
                new NameComponent('surname', 'Smith', '/smɪθ/'),
            ],
            phoneticSystem: 'ipa',
        ), $this->figure(1)->name);
    }

    public function testFigure3VendorSpecificProperties(): void
    {
        self::assertEquals(
            ['example.com:foo' => 'bar', 'example.com:foo2' => (object) ['bar' => 'baz']],
            $this->figure(3)->extra,
        );
    }

    public function testFigure4VendorSpecificValue(): void
    {
        self::assertSame('example.com:baz', $this->figure(4)->kind);
    }

    public function testFigure6BasicCard(): void
    {
        self::assertEquals(new Card(
            uid: '22B2C7DF-9120-4969-8460-05956FE6B065',
            kind: Card::KIND_INDIVIDUAL,
            name: new Name(
                components: [new NameComponent('given', 'John'), new NameComponent('surname', 'Doe')],
                isOrdered: true,
            ),
        ), $this->figure(6));
    }

    public function testFigures8And15Timestamps(): void
    {
        self::assertEquals(new \DateTimeImmutable('2022-09-30T14:35:10Z'), $this->figure(8)->created);
        self::assertEquals(new \DateTimeImmutable('2021-10-31T22:27:10Z'), $this->figure(15)->updated);
    }

    public function testFigure10Language(): void
    {
        self::assertSame('de-AT', $this->figure(10)->language);
    }

    public function testFigure11Members(): void
    {
        $card = $this->figure(11);

        self::assertSame(Card::KIND_GROUP, $card->kind);
        self::assertSame('urn:uuid:ab4310aa-fa43-11e9-8f0b-362b9e155667', $card->uid);
        self::assertEquals(new Name(full: 'The Doe family'), $card->name);
        self::assertSame([
            'urn:uuid:03a0e51f-d1aa-4385-8a53-e29025acd8af',
            'urn:uuid:b8767877-b4a1-4c70-9acc-505d3819e519',
        ], $card->members);
    }

    public function testFigure12ProdId(): void
    {
        self::assertSame('ACME Contacts App version 1.23.5', $this->figure(12)->prodId);
    }

    public function testFigure19SortAs(): void
    {
        self::assertEquals(new Name(
            components: [
                new NameComponent('given', 'Robert'),
                new NameComponent('given2', 'Pau'),
                new NameComponent('surname', 'Shou Chang'),
            ],
            isOrdered: true,
            sortAs: ['surname' => 'Pau Shou Chang', 'given' => 'Robert'],
        ), $this->figure(19)->name);
    }

    public function testFigure20KeepsLocalizationsVerbatim(): void
    {
        $card = $this->figure(20);

        self::assertSame('zh-Hant', $card->language);
        self::assertCount(4, $card->name->components ?? []);
        self::assertSame(['localizations'], array_keys($card->extra));
    }

    public function testFigure21Nicknames(): void
    {
        self::assertEquals(['k391' => new Nickname('Johnny')], $this->figure(21)->nicknames);
    }

    public function testFigure22Organizations(): void
    {
        self::assertEquals(
            ['o1' => new Organization('ABC, Inc.', [new OrgUnit('North American Division'), new OrgUnit('Marketing')], 'ABC')],
            $this->figure(22)->organizations,
        );
    }

    public function testFigure23SpeakToAs(): void
    {
        self::assertEquals(new SpeakToAs(SpeakToAs::GENDER_NEUTER, [
            'k19' => new Pronouns('they/them', pref: 2),
            'k32' => new Pronouns('xe/xir', pref: 1),
        ]), $this->figure(23)->speakToAs);
    }

    public function testFigure24Titles(): void
    {
        $card = $this->figure(24);

        self::assertEquals([
            'le9' => new Title('Research Scientist', Title::KIND_TITLE),
            'k2' => new Title('Project Leader', Title::KIND_ROLE, 'o2'),
        ], $card->titles);
        self::assertEquals(['o2' => new Organization('ABC, Inc.')], $card->organizations);
    }

    public function testFigure25Emails(): void
    {
        self::assertEquals([
            'e1' => new EmailAddress('jqpublic@xyz.example.com', contexts: ['work']),
            'e2' => new EmailAddress('jane_doe@example.com', pref: 1),
        ], $this->figure(25)->emails);
    }

    public function testFigure26OnlineServices(): void
    {
        self::assertEquals([
            'x1' => new OnlineService(uri: 'xmpp:alice@example.com'),
            'x2' => new OnlineService(service: 'Mastodon', uri: 'https://example2.com/@alice', user: '@alice@example2.com'),
        ], $this->figure(26)->onlineServices);
    }

    public function testFigure27Phones(): void
    {
        self::assertEquals([
            'tel0' => new Phone('tel:+1-555-555-5555;ext=5555', features: ['voice'], contexts: ['private'], pref: 1),
            'tel3' => new Phone('tel:+1-201-555-0123', contexts: ['work']),
        ], $this->figure(27)->phones);
    }

    public function testFigure31Address(): void
    {
        self::assertEquals(['k23' => new Address(
            components: [
                new AddressComponent('number', '54321'),
                new AddressComponent('separator', ' '),
                new AddressComponent('name', 'Oak St'),
                new AddressComponent('locality', 'Reston'),
                new AddressComponent('region', 'VA'),
                new AddressComponent('separator', ' '),
                new AddressComponent('postcode', '20190'),
                new AddressComponent('country', 'USA'),
            ],
            isOrdered: true,
            countryCode: 'US',
            contexts: ['work'],
            defaultSeparator: ', ',
        )], $this->figure(31)->addresses);
    }

    public function testFigure33AddressWithFullValue(): void
    {
        $card = $this->figure(33);

        self::assertSame('2-7-2 Marunouchi, Chiyoda-ku, Tokyo 100-8994', $card->addresses['k26']->full ?? null);
        self::assertCount(9, $card->addresses['k26']->components ?? []);
        self::assertSame(['localizations'], array_keys($card->extra));
    }

    public function testFigure37Links(): void
    {
        self::assertEquals(
            ['link3' => new Link('mailto:contact@example.com', kind: Link::KIND_CONTACT, pref: 1)],
            $this->figure(37)->links,
        );
    }

    public function testFigure41Anniversaries(): void
    {
        self::assertEquals([
            'k8' => new Anniversary(Anniversary::KIND_BIRTH, new PartialDate(1953, 4, 15)),
            'k9' => new Anniversary(
                Anniversary::KIND_DEATH,
                new Timestamp(new \DateTimeImmutable('2019-10-15T23:10:00Z')),
                new Address(full: "4445 Tree Street\nNew England, ND 58647\nUSA"),
            ),
        ], $this->figure(41)->anniversaries);
    }

    public function testFigure42Keywords(): void
    {
        self::assertSame(['internet', 'IETF'], $this->figure(42)->keywords);
    }

    public function testFigure43Notes(): void
    {
        self::assertEquals(['n1' => new Note(
            'Open office hours are 1600 to 1715 EST, Mon-Fri',
            created: new \DateTimeImmutable('2022-11-23T15:01:32Z'),
            author: new Author(name: 'John'),
        )], $this->figure(43)->notes);
    }

    private function figure(int $number): Card
    {
        $figure = json_decode((string) file_get_contents(\sprintf('%s/../Fixtures/Rfc9553/figure-%02d.json', __DIR__, $number)), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($figure);

        $result = new JsonDecoder()->decode(json_encode(['@type' => 'Card', 'version' => '2.0'] + $figure, \JSON_THROW_ON_ERROR));

        return $result->value;
    }
}
