<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\CryptoKey;
use Rondeto\JSContact\Model\Directory;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\LanguagePref;
use Rondeto\JSContact\Model\Link;
use Rondeto\JSContact\Model\Media;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\Organization;
use Rondeto\JSContact\Model\OrgUnit;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\PatchObject;
use Rondeto\JSContact\Model\PersonalInfo;
use Rondeto\JSContact\Model\Pronouns;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\Model\SpeakToAs;
use Rondeto\JSContact\Model\Title;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

final class VCardDecoderTest extends TestCase
{
    public function testItReadsEveryCardOfAFile(): void
    {
        $results = new VCardDecoder()->decode("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\nBEGIN:VCARD\r\nVERSION:4.0\r\nFN:B\r\nEND:VCARD\r\n");

        self::assertSame(['A', 'B'], array_map(static fn (Result $result): ?string => $result->value->name?->full, $results));
    }

    public function testAVCardWithoutUidGivesACardWithoutUid(): void
    {
        self::assertNull($this->decode("FN:A\r\n")->value->uid);
    }

    public function testVersion3PreferenceAndTypes(): void
    {
        $card = $this->decode("EMAIL;TYPE=INTERNET;TYPE=HOME;TYPE=PREF:a@example.com\r\nEMAIL;type=WORK:b@example.com\r\n", '3.0')->value;

        self::assertEquals([
            'EMAIL-1' => new EmailAddress('a@example.com', contexts: ['private'], pref: 1, vCardParams: ['type' => 'internet']),
            'EMAIL-2' => new EmailAddress('b@example.com', contexts: ['work']),
        ], $card->emails);
    }

    public function testVersion21BareParameters(): void
    {
        $card = $this->decode("TEL;HOME;VOICE;PREF:+33612345678\r\n", '2.1')->value;

        self::assertSame(['private'], $card->phones['PHONE-1']->contexts ?? null);
        self::assertSame(['voice'], $card->phones['PHONE-1']->features ?? null);
        self::assertSame(1, $card->phones['PHONE-1']->pref ?? null);
    }

    public function testAnEscapedCommaIsNotAListSeparator(): void
    {
        $card = $this->decode("ADR:;;123 Main St\\, Apt 4;Paris;;;\r\n")->value;

        self::assertEquals(
            [new AddressComponent('name', '123 Main St, Apt 4'), new AddressComponent('locality', 'Paris')],
            $card->addresses['ADDR-1']->components ?? null,
        );
    }

    public function testEachNicknameOfAListIsANickname(): void
    {
        $card = $this->decode("NICKNAME;PROP-ID=n1:Jim,Jimmie\r\n")->value;

        self::assertEquals(['n1' => new Nickname('Jim'), 'NICK-2' => new Nickname('Jimmie')], $card->nicknames);
    }

    public function testKeysAreStable(): void
    {
        $vCard = "EMAIL:a@example.com\r\nEMAIL;PROP-ID=EMAIL-1:b@example.com\r\nEMAIL:c@example.com\r\n";

        $first = $this->decode($vCard)->value;
        self::assertSame(['EMAIL-1-2', 'EMAIL-1', 'EMAIL-3'], array_keys($first->emails));
        self::assertEquals($first, $this->decode($vCard)->value);
    }

    public function testALabelWithoutLabeledPropertyIsKeptVerbatim(): void
    {
        $card = $this->decode("item1.ADR:;;1 Main St;;;;\r\nitem1.X-ABLabel:Weekends\r\n")->value;

        self::assertSame(['group' => 'item1'], $card->addresses['ADDR-1']->vCardParams ?? null);
        self::assertEquals([new VCardProperty('x-ablabel', ['group' => 'item1'], 'unknown', ['Weekends'])], $card->vCardProps);
    }

    public function testUnconvertiblePropertiesAreKeptVerbatimAndReported(): void
    {
        $result = $this->decode(implode("\r\n", [
            'UID:urn:uuid:1',
            'UID:urn:uuid:2',
            'REV:yesterday',
            'KIND:x-robot',
            'PRODID;X-FOO=bar:App',
            'TITLE;ALTID=1;LANGUAGE=en:Boss',
            'TITLE;ALTID=1;LANGUAGE=fr:Patron',
            'EMAIL;PREF=0;PROP-ID=a b:a@example.com',
            'garbage',
            '',
        ]));

        self::assertSame('urn:uuid:1', $result->value->uid);
        self::assertSame('Boss', $result->value->titles['TITLE-1']->name ?? null);
        self::assertEquals(['fr' => new PatchObject(['titles/TITLE-1/name' => 'Patron'])], $result->value->localizations);
        self::assertSame(['uid', 'rev', 'kind', 'prodid'], array_map(static fn (VCardProperty $property): string => $property->name, $result->value->vCardProps));
        self::assertSame([
            '/: line 11 is not a vCard property, ignored it',
            '/vCardProps/0: kept UID verbatim: more than one UID property',
            '/vCardProps/1: kept REV verbatim: "yesterday" is not a valid REV value',
            '/vCardProps/2: kept KIND verbatim: "x-robot" is not a valid KIND value',
            '/vCardProps/3: kept PRODID verbatim: the Card has no place for its parameters or group',
            '/: EMAIL: PROP-ID "a b" is not a valid or unique Id, generated another key',
            '/emails/EMAIL-1: PREF "0" is not between 1 and 100, ignored it',
        ], array_map(strval(...), $result->issues));
    }

    public function testOrganizationsAndTitles(): void
    {
        $result = $this->decode(implode("\r\n", [
            'ORG:;Research',
            'ORG:ACME;Sales;',
            'ORG:ACME;;Sales',
            'item1.ORG:A',
            'item1.ORG:B',
            'item1.TITLE;TYPE=work:Boss',
            '',
        ]));
        $card = $result->value;

        self::assertEquals(new Organization(units: [new OrgUnit('Research')]), $card->organizations['ORG-1'] ?? null);
        self::assertEquals(new Organization('ACME', [new OrgUnit('Sales')]), $card->organizations['ORG-2'] ?? null);
        // The third ORG is kept verbatim but still takes its position.
        self::assertSame(['ORG-1', 'ORG-2', 'ORG-4', 'ORG-5'], array_keys($card->organizations));
        // Two organizations in its group: the title's organization is unknown.
        self::assertEquals(new Title('Boss', Title::KIND_TITLE, vCardParams: ['type' => 'work', 'group' => 'item1']), $card->titles['TITLE-1'] ?? null);
        self::assertSame(['/vCardProps/0: kept ORG verbatim: an organizational unit has no name'], array_map(strval(...), $result->issues));
    }

    public function testTextWithCommasAndNewLinesIsUnescaped(): void
    {
        $card = $this->decode("NOTE:Line 1\\nLine 2, and more\r\n")->value;

        self::assertSame("Line 1\nLine 2, and more", $card->notes['NOTE-1']->note ?? null);
    }

    public function testAnniversaries(): void
    {
        $result = $this->decode(implode("\r\n", [
            'BDAY;CALSCALE=Hebrew:--0415',
            'BDAY:19800101',
            'BIRTHPLACE;VALUE=uri:geo:46.77,23.59',
            'DEATHDATE;VALUE=text:circa 1800',
            'DEATHPLACE:Paris',
            'ANNIVERSARY:1985-04',
            'ANNIVERSARY:19851012T1200',
            '',
        ]));
        $card = $result->value;

        self::assertEquals([
            'ANNIVERSARY-1' => new Anniversary('birth', new PartialDate(month: 4, day: 15, calendarScale: 'hebrew'), new Address(coordinates: 'geo:46.77,23.59')),
            'ANNIVERSARY-4' => new Anniversary('wedding', new PartialDate(1985, 4)),
        ], $card->anniversaries);
        self::assertSame([
            '/vCardProps/0: kept BDAY verbatim: more than one BDAY property',
            '/vCardProps/1: kept DEATHDATE verbatim: a date as text has no JSContact counterpart',
            '/vCardProps/2: kept ANNIVERSARY verbatim: not a date, nor a UTC date-time',
            '/vCardProps/3: kept DEATHPLACE verbatim: no DEATHDATE property to attach the place to',
        ], array_map(strval(...), $result->issues));
    }

    public function testVersion3Dates(): void
    {
        $card = $this->decode("BDAY:1996-04-15\r\n", '3.0')->value;

        self::assertEquals(new PartialDate(1996, 4, 15), $card->anniversaries['ANNIVERSARY-1']->date ?? null);
    }

    public function testSpeakToAs(): void
    {
        $result = $this->decode(implode("\r\n", [
            'GRAMGENDER;LANGUAGE=de:Feminine',
            'GRAMGENDER;LANGUAGE=fr:masculine',
            'PRONOUNS;LANGUAGE=en;TYPE=work:she/her',
            '',
        ]));

        self::assertEquals(new SpeakToAs(
            'feminine',
            ['PRONOUNS-1' => new Pronouns('she/her', ['work'], vCardParams: ['language' => 'en'])],
            vCardParams: ['language' => 'de'],
        ), $result->value->speakToAs);
        // A Card has one grammatical gender: the others are its versions in other languages.
        self::assertEquals(['fr' => new PatchObject(['speakToAs/grammaticalGender' => 'masculine'])], $result->value->localizations);
        self::assertSame([], array_map(strval(...), $result->issues));
    }

    public function testVersion3MediaAreEmbeddedOrTyped(): void
    {
        $result = $this->decode(implode("\r\n", [
            'PHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQ',
            'LOGO;VALUE=uri;TYPE=PNG;TYPE=work:https://example.com/logo.png',
            'KEY;ENCODING=b;TYPE=PGP:LS0tLS1C',
            'KEY;VALUE=text:not a uri',
            'ORG-DIRECTORY;INDEX=first:ldap://ldap.example.com',
            '',
        ]), '3.0');
        $card = $result->value;

        self::assertEquals([
            'PHOTO-1' => new Media('data:image/jpeg;base64,/9j/4AAQ', Media::KIND_PHOTO),
            'LOGO-1' => new Media('https://example.com/logo.png', Media::KIND_LOGO, 'image/png', ['work']),
        ], $card->media);
        self::assertEquals(['KEY-1' => new CryptoKey('data:application/pgp-keys;base64,LS0tLS1C')], $card->cryptoKeys);
        self::assertEquals(['DIRECTORY-1' => new Directory('ldap://ldap.example.com', Directory::KIND_DIRECTORY, vCardParams: ['index' => 'first'])], $card->directories);
        self::assertSame([
            '/vCardProps/0: kept KEY verbatim: not a URI',
            '/: ORG-DIRECTORY: INDEX "first" is not a positive integer, kept it in vCardParams',
        ], array_map(strval(...), $result->issues));
    }

    public function testLanguagesRelationsAndPersonalInfo(): void
    {
        $result = $this->decode(implode("\r\n", [
            'LANG;PREF=1:fr-CA',
            'LANG:Klingon!',
            'RELATED;TYPE=spouse,kin:urn:uuid:1',
            'RELATED;TYPE=friend:urn:uuid:1',
            'item1.HOBBY;LEVEL=Low:chess',
            'item1.X-ABLabel:Weekends',
            'EXPERTISE;LEVEL=average;INDEX=one:law',
            '',
        ]));
        $card = $result->value;

        self::assertEquals(['LANG-1' => new LanguagePref('fr-CA', pref: 1)], $card->preferredLanguages);
        self::assertEquals(['urn:uuid:1' => new Relation(['spouse', 'kin'])], $card->relatedTo);
        self::assertEquals([
            'PERSINFO-1' => new PersonalInfo('hobby', 'chess', 'low', label: 'Weekends', vCardParams: ['group' => 'item1']),
            'PERSINFO-2' => new PersonalInfo('expertise', 'law', 'medium', vCardParams: ['index' => 'one']),
        ], $card->personalInfo);
        self::assertSame([
            '/vCardProps/0: kept LANG verbatim: "Klingon!" is not a language tag',
            '/vCardProps/1: kept RELATED verbatim: another RELATED property has the same value',
            '/: EXPERTISE: INDEX "one" is not a positive integer, kept it in vCardParams',
        ], array_map(strval(...), $result->issues));
    }

    public function testGeographyGoesToTheAddressOfItsGroup(): void
    {
        $card = $this->decode(implode("\r\n", [
            'item1.ADR:;;1 Main St;Paris;;;',
            'item1.GEO:geo:48.85,2.35',
            'item1.TZ:Europe/Paris',
            'ADR:;;2 Side St;Lyon;;;',
            'GEO:geo:45.76,4.83',
            '',
        ]))->value;

        // The vCard uses groups, so the ungrouped GEO goes to the ungrouped ADR (RFC 9555, section 2.8.3).
        self::assertSame(['ADDR-1', 'ADDR-2'], array_keys($card->addresses));
        self::assertSame(['geo:48.85,2.35', 'Europe/Paris'], [$card->addresses['ADDR-1']->coordinates ?? null, $card->addresses['ADDR-1']->timeZone ?? null]);
        self::assertSame('geo:45.76,4.83', $card->addresses['ADDR-2']->coordinates ?? null);
    }

    public function testUngroupedGeographyMakesAnAddressOfItsOwn(): void
    {
        $result = $this->decode(implode("\r\n", [
            'ADR:;;1 Main St;Paris;;;',
            'GEO:48.85;2.35',
            'TZ:-05:00',
            'TZ:+05:30',
            '',
        ]), '3.0');

        self::assertEquals(new Address(coordinates: 'geo:48.85,2.35', timeZone: 'Etc/GMT+5'), $result->value->addresses['GEO-1'] ?? null);
        self::assertNull($result->value->addresses['ADDR-1']->coordinates ?? null);
        self::assertSame(['/vCardProps/0: kept TZ verbatim: not a time zone, nor a UTC offset in whole hours from -12 to +14'], array_map(strval(...), $result->issues));
    }

    public function testGeographyWithParametersOrForAFilledAddressIsNotMerged(): void
    {
        $card = $this->decode(implode("\r\n", [
            'item1.ADR;GEO="geo:1,1":;;1 Main St;Paris;;;',
            'item1.GEO:geo:2,2',
            'item2.ADR:;;2 Side St;Lyon;;;',
            'item2.GEO;TYPE=work:geo:3,3',
            '',
        ]))->value;

        self::assertSame('geo:1,1', $card->addresses['ADDR-1']->coordinates ?? null);
        self::assertEquals(new Address(coordinates: 'geo:2,2', vCardParams: ['group' => 'item1']), $card->addresses['GEO-1'] ?? null);
        self::assertNull($card->addresses['ADDR-2']->coordinates ?? null);
        self::assertEquals(new Address(coordinates: 'geo:3,3', contexts: ['work'], vCardParams: ['group' => 'item2']), $card->addresses['GEO-2'] ?? null);
    }

    public function testRepeatedValueParametersAreReported(): void
    {
        $result = $this->decode("PRODID;VALUE=text;VALUE=TEXT:App\r\n");

        self::assertSame('App', $result->value->prodId);
        self::assertSame(['/: line 3 repeats the VALUE parameter, kept the first one'], array_map(strval(...), $result->issues));
    }

    public function testListedValueParametersAreReported(): void
    {
        $results = new VCardDecoder()->decode("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nURL;TYPE=work;VALUE=uri,text:https://jane.example\r\nEND:VCARD\r\n");
        self::assertCount(1, $results);
        $card = $results[0]->value;

        self::assertSame('Jane Doe', $card->name?->full);
        self::assertEquals(new Link('https://jane.example', contexts: ['work']), $card->links['LINK-1'] ?? null);
        self::assertSame(['/: line 4 lists several values in the VALUE parameter, kept the first one'], array_map(strval(...), $results[0]->issues));
        self::assertStringContainsString("\r\nURL;PROP-ID=LINK-1;TYPE=work:https://jane.example\r\n", new VCardEncoder()->encode($card)->value);
    }

    public function testRepeatedAndListedValueParametersAreReported(): void
    {
        $result = $this->decode("URL;VALUE=uri,text;VALUE=text:https://jane.example\r\n");

        self::assertSame('https://jane.example', $result->value->links['LINK-1']->uri ?? null);
        self::assertSame(['/: line 3 repeats the VALUE parameter, kept the first one'], array_map(strval(...), $result->issues));
    }

    public function testADerivedFullNameIsNotKept(): void
    {
        $card = $this->decode("N:Doe;Jane;;;\r\nFN;DERIVED=TRUE:Jane Doe\r\n")->value;

        self::assertNull($card->name?->full);
        self::assertSame([], $card->vCardProps);
    }

    public function testAnInvalidPatchIsKeptVerbatim(): void
    {
        $result = $this->decode("JSPROP;JSPTR=\"emails/e1/x\":1\r\n");

        self::assertSame(['jsprop'], array_map(static fn (VCardProperty $property): string => $property->name, $result->value->vCardProps));
        self::assertSame(['/: kept the JSPROP properties verbatim: "emails/e1/x" points into a value that does not exist'], array_map(strval(...), $result->issues));
    }

    public function testStrictModeRefusesAVCardWithIssues(): void
    {
        $this->expectException(InvalidCardException::class);

        new VCardDecoder(strict: true)->decode("BEGIN:VCARD\r\nVERSION:4.0\r\nREV:yesterday\r\nEND:VCARD\r\n");
    }

    public function testItConvertsASabreVCard(): void
    {
        $vCard = Reader::read("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:urn:uuid:1\r\nEND:VCARD\r\n");
        self::assertInstanceOf(VCard::class, $vCard);

        self::assertEquals(new Card(uid: 'urn:uuid:1'), new VCardDecoder()->convert($vCard)->value);
    }

    public function testVersionsInTheSameLanguageAreKeptVerbatim(): void
    {
        $result = $this->decode(implode("\r\n", [
            'TITLE;ALTID=1:Boss',
            'TITLE;ALTID=1;LANGUAGE=fr:Patron',
            'TITLE;ALTID=1;LANGUAGE=fr:Chef',
            '',
        ]));

        self::assertEquals(['fr' => new PatchObject(['titles/TITLE-1/name' => 'Patron'])], $result->value->localizations);
        self::assertSame(['/vCardProps/0: kept TITLE verbatim: another version in the same language'], array_map(strval(...), $result->issues));
    }

    public function testAPhoneticAddressWithoutItsAddressIsKeptVerbatim(): void
    {
        $result = $this->decode("ADR;ALTID=1:;;;香港;;;\r\nADR;ALTID=2;PHONETIC=jyut:;;;hoeng1gong2;;;\r\n");

        self::assertNull($result->value->addresses['ADR-1']->phoneticSystem ?? null);
        self::assertSame(['/vCardProps/0: kept ADR verbatim: no ADR property relates to it by ALTID'], array_map(strval(...), $result->issues));
    }

    public function testJsPropIntoTheLocalizationsAppliesOnceTheyAreConverted(): void
    {
        $result = $this->decode(implode("\r\n", [
            'TITLE;ALTID=1:Boss',
            'TITLE;ALTID=1;LANGUAGE=fr:Patron',
            'JSPROP;JSPTR=localizations/de:{"titles/TITLE-1/name":"Chef"}',
            '',
        ]));

        self::assertEquals([
            'fr' => new PatchObject(['titles/TITLE-1/name' => 'Patron']),
            'de' => new PatchObject(['titles/TITLE-1/name' => 'Chef']),
        ], $result->value->localizations);
        self::assertSame([], array_map(strval(...), $result->issues));

        $result = $this->decode('JSPROP;JSPTR=localizations/de/x:1'."\r\n");

        self::assertSame([], $result->value->localizations);
        self::assertSame('jsprop', $result->value->vCardProps[0]->name ?? null);
        self::assertSame(['/: kept the JSPROP properties verbatim: "localizations/de/x" points into a value that does not exist'], array_map(strval(...), $result->issues));
    }

    /**
     * @return Result<Card>
     */
    private function decode(string $properties, string $version = '4.0'): Result
    {
        $results = new VCardDecoder()->decode("BEGIN:VCARD\r\nVERSION:".$version."\r\n".$properties."END:VCARD\r\n");
        self::assertCount(1, $results);

        return $results[0];
    }
}
