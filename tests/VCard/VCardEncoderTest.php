<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Timestamp;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

final class VCardEncoderTest extends TestCase
{
    public function testACardWithoutNameGetsAnEmptyFullName(): void
    {
        self::assertSame("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:\r\nEND:VCARD\r\n", new VCardEncoder()->encode(new Card())->value);
    }

    public function testLabelsGetAGroupOfTheirOwn(): void
    {
        $card = new Card(
            emails: ['e1' => new EmailAddress('a@example.com', label: 'Home')],
            vCardProps: [new VCardProperty('x-foo', ['group' => 'item1'], 'unknown', ['bar'])],
        );

        $vCard = new VCardEncoder()->encode($card)->value;

        self::assertStringContainsString("item2.EMAIL;PROP-ID=e1:a@example.com\r\nitem2.X-ABLABEL:Home\r\n", $vCard);
        self::assertStringContainsString("item1.X-FOO:bar\r\n", $vCard);
    }

    public function testVersion3HasNoPreferenceOrder(): void
    {
        $card = new Card(kind: Card::KIND_GROUP, phones: ['p1' => new Phone('+33612345678', contexts: ['work'], pref: 2)]);

        $result = new VCardEncoder()->encode($card, VCardVersion::V30);

        self::assertStringContainsString('TEL;PROP-ID=p1;TYPE=work,pref:+33612345678', $result->value);
        self::assertSame([
            '/kind: vCard 3.0 does not define KIND, wrote it anyway',
            '/phones/p1/pref: vCard 3.0 has no preference order, wrote preference 2 as TYPE=pref',
        ], array_map(strval(...), $result->issues));
    }

    public function testWhatVCardCannotHoldIsReported(): void
    {
        $card = new Card(
            phones: ['p1' => new Phone('tel:+1', features: ['hologram'], contexts: ['example.com:car'])],
            extra: ['localizations' => ['fr' => ['name/full' => 'ACME']]],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString('JSPROP;JSPTR=localizations:{"fr":{"name/full":"ACME"}}', $result->value);
        self::assertSame([
            '/phones/p1/features/hologram: vCard has no TEL type for this feature, left it out',
            '/phones/p1/contexts/example.com:car: vCard has no TYPE for this context, left it out',
            '/localizations: not converted to vCard properties yet, wrote it as JSPROP',
        ], array_map(strval(...), $result->issues));
    }

    public function testItRefusesAnInvalidCardUnlessValidationIsOff(): void
    {
        $card = new Card(emails: ['e1' => new EmailAddress('a@example.com', pref: 0)]);

        self::assertStringContainsString('EMAIL;PREF=0;PROP-ID=e1:a@example.com', new VCardEncoder(validate: false)->encode($card)->value);

        $this->expectException(InvalidCardException::class);
        new VCardEncoder()->encode($card);
    }

    public function testATitleSharesTheGroupOfItsOrganization(): void
    {
        $card = new Card(
            organizations: ['o1' => new Organization('ACME', [new OrgUnit('Sales', extra: ['example.com:x' => 1])])],
            titles: ['t1' => new Title('Lead', Title::KIND_ROLE, 'o1'), 't2' => new Title('Chair', 'example.com:chair')],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString("item1.ORG;PROP-ID=o1:ACME;Sales\r\n", $result->value);
        self::assertStringContainsString("item1.ROLE;PROP-ID=t1:Lead\r\n", $result->value);
        self::assertStringContainsString("TITLE;PROP-ID=t2:Chair\r\n", $result->value);
        self::assertSame([
            '/titles/t2/kind: vCard has no title of kind "example.com:chair", wrote a TITLE',
            '/organizations/o1/units/0: properties of objects in arrays cannot be written to vCard, left them out',
        ], array_map(strval(...), $result->issues));
    }

    public function testAnniversaries(): void
    {
        $card = new Card(anniversaries: [
            'a1' => new Anniversary('birth', new PartialDate(month: 4, day: 15, calendarScale: 'hebrew'), new Address(full: "1 Main St\nParis")),
            'a2' => new Anniversary('death', new Timestamp(new \DateTimeImmutable('2019-10-15T23:10:00Z')), new Address(coordinates: 'geo:1,2')),
            'a3' => new Anniversary('wedding', new PartialDate(1985, 4, 12), new Address(full: 'Rome')),
            'a4' => new Anniversary('example.com:graduation', new PartialDate(2001)),
        ]);

        $v40 = new VCardEncoder()->encode($card);

        self::assertStringContainsString("BDAY;CALSCALE=hebrew;PROP-ID=a1:--0415\r\nBIRTHPLACE:1 Main St\\nParis\r\n", $v40->value);
        self::assertStringContainsString("DEATHDATE;PROP-ID=a2:20191015T231000Z\r\nDEATHPLACE;VALUE=uri:geo:1,2\r\n", $v40->value);
        // vCard escapes commas in text values.
        self::assertStringContainsString('JSPROP;JSPTR=anniversaries/a4:{"kind":"example.com:graduation"\\,"date"', str_replace("\r\n ", '', $v40->value));
        self::assertStringContainsString("ANNIVERSARY;PROP-ID=a3:19850412\r\n", $v40->value);
        self::assertStringContainsString('JSPROP;JSPTR=anniversaries/a3/place:{"full":"Rome"}', $v40->value);
        self::assertEquals($card, new VCardDecoder()->decode($v40->value)[0]->value ?? null);

        $v30 = new VCardEncoder()->encode(new Card(anniversaries: ['a1' => $card->anniversaries['a1'], 'a2' => $card->anniversaries['a2']]), VCardVersion::V30);
        self::assertStringContainsString('DEATHDATE;PROP-ID=a2:2019-10-15T23:10:00Z', $v30->value);
        self::assertStringContainsString('JSPROP;JSPTR=anniversaries/a1:', $v30->value);
    }

    public function testUriValuesAreNotEscaped(): void
    {
        $card = new Card(links: ['l1' => new Link('https://example.com/?a=1,2')]);

        $vCard = new VCardEncoder()->encode($card)->value;

        self::assertStringContainsString("URL;PROP-ID=l1:https://example.com/?a=1,2\r\n", $vCard);
        self::assertEquals($card, new VCardDecoder()->decode($vCard)[0]->value ?? null);
    }

    public function testSpeakToAs(): void
    {
        $vendor = new Card(speakToAs: new SpeakToAs('example.com:honorific'));
        $result = new VCardEncoder()->encode($vendor);

        self::assertStringContainsString('JSPROP;JSPTR=speakToAs:{"grammaticalGender":"example.com:honorific"}', $result->value);
        self::assertEquals($vendor, new VCardDecoder()->decode($result->value)[0]->value ?? null);

        $card = new Card(speakToAs: new SpeakToAs('neuter', ['p1' => new Pronouns('they/them', ['private'], 1)]));
        $result = new VCardEncoder()->encode($card, VCardVersion::V30);

        self::assertStringContainsString("GRAMGENDER:neuter\r\nPRONOUNS;PROP-ID=p1;TYPE=home,pref:they/them\r\n", $result->value);
        self::assertSame([
            '/speakToAs/grammaticalGender: vCard 3.0 does not define GRAMGENDER, wrote it anyway',
            '/speakToAs/pronouns/p1: vCard 3.0 does not define PRONOUNS, wrote it anyway',
        ], array_map(strval(...), $result->issues));
    }
}
