<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\CryptoKey;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\LanguagePref;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Media;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\PatchObject;
use Rondeto\JSContact\Model\PersonalInfo;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Timestamp;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\Target;
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

        $result = new VCardEncoder()->encode($card, new Target(VCardVersion::V30));

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
            extra: ['example.com:map' => ['fr' => ['name/full' => 'ACME']]],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString('JSPROP;JSPTR="example.com:map":{"fr":{"name/full":"ACME"}}', $result->value);
        self::assertSame([
            '/phones/p1/features/hologram: vCard has no TEL type for this feature, left it out',
            '/phones/p1/contexts/example.com:car: vCard has no TYPE for this context, left it out',
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

        $v30 = new VCardEncoder()->encode(new Card(anniversaries: ['a1' => $card->anniversaries['a1'], 'a2' => $card->anniversaries['a2']]), new Target(VCardVersion::V30));
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
        $result = new VCardEncoder()->encode($card, new Target(VCardVersion::V30));

        self::assertStringContainsString("GRAMGENDER:neuter\r\nPRONOUNS;PROP-ID=p1;TYPE=home,pref:they/them\r\n", $result->value);
        self::assertSame([
            '/speakToAs/grammaticalGender: vCard 3.0 does not define GRAMGENDER, wrote it anyway',
            '/speakToAs/pronouns/p1: vCard 3.0 does not define PRONOUNS, wrote it anyway',
        ], array_map(strval(...), $result->issues));
    }

    public function testResources(): void
    {
        $card = new Card(
            media: [
                'm1' => new Media('data:image/jpeg;base64,/9j/4AAQ', Media::KIND_PHOTO),
                'm2' => new Media('https://example.com/a,b.png', Media::KIND_LOGO, 'image/png'),
                'm3' => new Media('https://example.com/v.mp4', 'example.com:video'),
            ],
            cryptoKeys: ['k1' => new CryptoKey('https://example.com/key.asc', 'example.com:pgp')],
        );

        $v40 = new VCardEncoder()->encode($card);
        self::assertStringContainsString("PHOTO;PROP-ID=m1:data:image/jpeg;base64,/9j/4AAQ\r\n", $v40->value);
        self::assertStringContainsString("LOGO;MEDIATYPE=image/png;PROP-ID=m2:https://example.com/a,b.png\r\n", $v40->value);
        self::assertSame([
            '/media/m3: vCard has no media of kind "example.com:video", wrote it as JSPROP',
            '/cryptoKeys/k1/kind: vCard has no kind of key, wrote it as JSPROP',
        ], array_map(strval(...), $v40->issues));
        self::assertEquals($card, new VCardDecoder()->decode($v40->value)[0]->value ?? null);

        $v30 = new VCardEncoder()->encode(new Card(media: ['m1' => $card->media['m1'], 'm2' => $card->media['m2']]), new Target(VCardVersion::V30));
        self::assertStringContainsString("PHOTO;ENCODING=b;PROP-ID=m1;TYPE=JPEG:/9j/4AAQ\r\n", $v30->value);
        self::assertStringContainsString("LOGO;VALUE=uri;PROP-ID=m2;TYPE=PNG:https://example.com/a,b.png\r\n", $v30->value);
    }

    public function testLanguagesRelationsAndPersonalInfo(): void
    {
        $card = new Card(
            preferredLanguages: ['l1' => new LanguagePref('fr', ['work'], 1)],
            relatedTo: [
                'https://example.com/jane.vcf' => new Relation(['friend', 'example.com:mentor']),
                'My deputy, John' => new Relation(),
            ],
            personalInfo: [
                'p1' => new PersonalInfo('expertise', 'chemistry', 'high', 1),
                'p2' => new PersonalInfo('example.com:skill', 'juggling'),
            ],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString("LANG;PREF=1;PROP-ID=l1;TYPE=work:fr\r\n", $result->value);
        self::assertStringContainsString("RELATED;TYPE=friend:https://example.com/jane.vcf\r\n", $result->value);
        self::assertStringContainsString("RELATED;VALUE=text:My deputy\\, John\r\n", $result->value);
        self::assertStringContainsString("EXPERTISE;LEVEL=expert;INDEX=1;PROP-ID=p1:chemistry\r\n", $result->value);
        self::assertSame([
            '/relatedTo/https:~1~1example.com~1jane.vcf/relation/example.com:mentor: vCard has no TYPE for this relation, wrote it as JSPROP',
            '/personalInfo/p2: vCard has no personal information of kind "example.com:skill", wrote it as JSPROP',
        ], array_map(strval(...), $result->issues));
        self::assertEquals($card, new VCardDecoder()->decode($result->value)[0]->value ?? null);
    }

    public function testAnAddressWithOnlyCoordinatesAndTimeZoneIsWrittenAsGeoAndTz(): void
    {
        $alone = new Card(addresses: ['a1' => new Address(coordinates: 'geo:48.85,2.35', timeZone: 'Etc/GMT+5', contexts: ['work'])]);

        $v40 = new VCardEncoder()->encode($alone)->value;
        self::assertStringContainsString("GEO;PROP-ID=a1;TYPE=work:geo:48.85,2.35\r\nTZ:Etc/GMT+5\r\n", $v40);
        self::assertEquals($alone, new VCardDecoder()->decode($v40)[0]->value ?? null);

        $v30 = new VCardEncoder()->encode($alone, new Target(VCardVersion::V30))->value;
        self::assertStringContainsString("GEO;PROP-ID=a1;TYPE=work:48.85;2.35\r\nTZ:-05:00\r\n", $v30);
        self::assertEquals($alone, new VCardDecoder()->decode($v30)[0]->value ?? null);

        // With another address, GEO and TZ share a group to come back together.
        $withOther = new Card(addresses: [
            'a1' => new Address(coordinates: 'geo:1,1', full: '1 Main St, Paris'),
            'a2' => new Address(coordinates: 'geo:2,2', timeZone: 'Europe/Paris'),
        ]);
        $v40 = new VCardEncoder()->encode($withOther)->value;
        self::assertStringContainsString('ADR;LABEL="1 Main St, Paris";GEO="geo:1,1";PROP-ID=a1:;;;;;;', $v40);
        self::assertStringContainsString("item1.GEO;PROP-ID=a2:geo:2,2\r\nitem1.TZ:Europe/Paris\r\n", $v40);
        $again = new VCardDecoder()->decode($v40)[0]->value ?? null;
        self::assertSame('Europe/Paris', $again?->addresses['a2']->timeZone);
    }

    public function testLocalizationsAreWrittenAsVersionsInOtherLanguages(): void
    {
        $card = new Card(
            language: 'en',
            name: new Name([new NameComponent('surname', 'Doe'), new NameComponent('given', 'John')], full: 'John Doe'),
            speakToAs: new SpeakToAs('masculine', ['k1' => new Pronouns('he/him')]),
            titles: ['t1' => new Title('Boss', Title::KIND_TITLE)],
            emails: ['e1' => new EmailAddress('john@example.com', label: 'Work', vCardParams: ['group' => 'item1'])],
            localizations: [
                'fr' => new PatchObject(['titles/t1/name' => 'Patron', 'speakToAs/pronouns/k1/pronouns' => 'il', 'emails/e1/address' => 'jean@example.fr']),
                'ja' => new PatchObject(['name/full' => 'ジョン・ドウ', 'name/components/0/value' => 'ドウ', 'name/components/1/value' => 'ジョン']),
            ],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertSame([], array_map(strval(...), $result->issues));
        self::assertSame([
            'BEGIN:VCARD',
            'VERSION:4.0',
            'LANGUAGE:en',
            'N;ALTID=4:Doe;John;;;;;',
            'N;ALTID=4;LANGUAGE=ja:ドウ;ジョン;;;;;',
            'FN;ALTID=4:John Doe',
            'FN;ALTID=4;LANGUAGE=ja:ジョン・ドウ',
            'item1.EMAIL;PROP-ID=e1;ALTID=3:john@example.com',
            'item1.EMAIL;PROP-ID=e1;ALTID=3;LANGUAGE=fr:jean@example.fr',
            'item1.X-ABLABEL:Work',
            'GRAMGENDER:masculine',
            'PRONOUNS;PROP-ID=k1;ALTID=1:he/him',
            'PRONOUNS;PROP-ID=k1;ALTID=1;LANGUAGE=fr:il',
            'TITLE;PROP-ID=t1;ALTID=2:Boss',
            'TITLE;PROP-ID=t1;ALTID=2;LANGUAGE=fr:Patron',
            'END:VCARD',
        ], explode("\r\n", rtrim($result->value)));
        self::assertEquals($card, new VCardDecoder()->decode($result->value)[0]->value ?? null);
    }

    public function testPhoneticsAreWrittenAsPhoneticVersions(): void
    {
        $card = new Card(
            language: 'zh-Hant',
            name: new Name([new NameComponent('surname', '孫'), new NameComponent('given', '中山')]),
            addresses: ['a1' => new Address(
                [new AddressComponent('locality', '香港', 'hoeng1gong2'), new AddressComponent('country', '中國')],
                phoneticScript: 'Latn',
                phoneticSystem: 'jyut',
            )],
            localizations: ['yue' => new PatchObject([
                'name/phoneticSystem' => 'jyut',
                'name/components/0/phonetic' => 'syun1',
                'name/components/1/phonetic' => 'zung1saan1',
            ])],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertSame([], array_map(strval(...), $result->issues));
        // Only the phonetics are localized: only the phonetic N has a version in that language.
        self::assertStringContainsString("N;ALTID=2:孫;中山;;;;;\r\nN;PHONETIC=jyut;ALTID=2;LANGUAGE=yue:syun1;zung1saan1;;;;;\r\nFN;DERIVED=TRUE:中山 孫\r\n", $result->value);
        self::assertStringContainsString("ADR;PROP-ID=a1;ALTID=1:;;;香港;;;中國\r\nADR;PHONETIC=jyut;SCRIPT=Latn;ALTID=1:;;;hoeng1gong2;;;\r\n", $result->value);
        self::assertEquals($card, new VCardDecoder()->decode($result->value)[0]->value ?? null);
    }

    public function testLocalizationsVCardCannotHoldAreWrittenAsJsProp(): void
    {
        $card = new Card(
            titles: ['t1' => new Title('Boss', Title::KIND_TITLE)],
            emails: ['e1' => new EmailAddress('john@example.com', label: 'Work', vCardParams: ['group' => 'item1'])],
            localizations: [
                'fr' => new PatchObject(['titles/t1/name' => 'Patron', 'emails/e1/label' => 'Travail']),
                'de' => new PatchObject(['titles/t1/name' => 'Chef']),
            ],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertSame(['/localizations/fr: vCard cannot hold this localization, wrote it as JSPROP'], array_map(strval(...), $result->issues));
        self::assertStringContainsString("TITLE;PROP-ID=t1;ALTID=1;LANGUAGE=de:Chef\r\n", $result->value);
        self::assertStringContainsString('JSPROP;JSPTR=localizations/fr:{"titles/t1/name":"Patron"\\,"emails/e1/label":"Travail"}', str_replace("\r\n ", '', $result->value));
        self::assertEquals($card, new VCardDecoder()->decode($result->value)[0]->value ?? null);

        // Without any language vCard can hold, the whole map.
        $card = new Card(titles: ['t1' => new Title('Boss', Title::KIND_TITLE)], localizations: ['fr' => new PatchObject(['titles/t2' => (object) ['name' => 'Patron']])]);
        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString('JSPROP;JSPTR=localizations:{"fr":{"titles/t2":{"name":"Patron"}}}', $result->value);
        self::assertStringNotContainsString('ALTID', $result->value);
        self::assertEquals($card, new VCardDecoder()->decode($result->value)[0]->value ?? null);
    }
}
