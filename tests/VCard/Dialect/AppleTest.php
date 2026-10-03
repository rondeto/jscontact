<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard\Dialect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\AddressComponent;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\VCard\Dialect\Apple;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

final class AppleTest extends TestCase
{
    public function testAnIosExportConvertsToTypedProperties(): void
    {
        $result = new VCardDecoder(dialects: [new Apple()])->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/ios-full.vcf'))[0];
        $card = $result->value;

        self::assertSame([], array_map(strval(...), $result->issues));
        self::assertSame(['parent'], $card->relatedTo['Mother']->relation ?? null);
        self::assertSame(['sibling'], $card->relatedTo['Brother']->relation ?? null);
        self::assertSame(['agent'], $card->relatedTo['Assistant']->relation ?? null);
        self::assertSame([], $card->relatedTo['Custom Relation']->relation ?? null);
        self::assertEquals(new PartialDate(2013, 3, 12), $card->anniversaries['ANNIVERSARY-2']->date ?? null);
        self::assertSame(Anniversary::KIND_WEDDING, $card->anniversaries['ANNIVERSARY-2']->kind ?? null);
        self::assertSame('fr', $card->addresses['ADDR-1']->countryCode ?? null);
        self::assertSame('home page', $card->links['LINK-1']->label ?? null);
        self::assertSame('other', $card->emails['EMAIL-4']->label ?? null);
        self::assertSame('iCloud', $card->emails['EMAIL-3']->label ?? null);
        self::assertSame('Skype', $card->onlineServices['OS-1']->service ?? null);
        $twitter = $card->onlineServices['OS-12'] ?? null;
        self::assertSame(['twitter', 'http://twitter.com/twitteruser', 'twitteruser'], [$twitter?->service, $twitter?->uri, $twitter?->user]);
        self::assertSame('Customsocial', $card->onlineServices['OS-18']->service ?? null);

        // Without the dialect, all of this stays verbatim.
        $plain = new VCardDecoder()->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/ios-full.vcf'))[0]->value;
        self::assertSame([], $plain->relatedTo);
        self::assertGreaterThan(\count($card->vCardProps), \count($plain->vCardProps));
    }

    public function testAYearOf1604IsNoYear(): void
    {
        $card = $this->read(implode("\r\n", ['BDAY;value=date:1604-02-02', 'item1.X-ABDATE;X-APPLE-OMIT-YEAR=1604:1604-12-24', 'item1.X-ABLabel:_$!<Anniversary>!$_', '']));

        self::assertEquals(new PartialDate(null, 2, 2), $card->anniversaries['ANNIVERSARY-1']->date ?? null);
        self::assertEquals(new PartialDate(null, 12, 24), $card->anniversaries['ANNIVERSARY-2']->date ?? null);
    }

    public function testCompaniesAndGroups(): void
    {
        self::assertSame(Card::KIND_ORG, $this->read("X-ABShowAs:COMPANY\r\n")->kind);

        $group = $this->read("X-ADDRESSBOOKSERVER-KIND:group\r\nX-ADDRESSBOOKSERVER-MEMBER:urn:uuid:03a0e51f-d1aa-4385-8a53-e29025acd8af\r\n");
        self::assertSame(Card::KIND_GROUP, $group->kind);
        self::assertSame(['urn:uuid:03a0e51f-d1aa-4385-8a53-e29025acd8af'], $group->members);
    }

    public function testACardIsWrittenAsAppleReadsIt(): void
    {
        $card = new Card(
            kind: Card::KIND_ORG,
            addresses: ['a1' => new Address([new AddressComponent('locality', 'Paris')], countryCode: 'FR')],
            anniversaries: [
                'b' => new Anniversary(Anniversary::KIND_BIRTH, new PartialDate(null, 4, 12)),
                'w' => new Anniversary(Anniversary::KIND_WEDDING, new PartialDate(2010, 6, 12)),
            ],
            relatedTo: ['Jane' => new Relation(['spouse']), 'Bob' => new Relation(['agent']), 'Ann' => new Relation(['co-worker'])],
        );

        $result = new VCardEncoder(dialects: [new Apple()])->encode($card);
        $lines = explode("\r\n", $result->value);

        self::assertSame([], array_map(strval(...), $result->issues));
        foreach ([
            'X-ABSHOWAS:COMPANY',
            'BDAY;PROP-ID=b;X-APPLE-OMIT-YEAR=1604:1604-04-12',
            'item1.ADR;PROP-ID=a1:;;;Paris;;;',
            'item1.X-ABADR:FR',
            'item2.X-ABDATE;PROP-ID=w:2010-06-12',
            'item2.X-ABLABEL:_$!<Anniversary>!$_',
            'item3.X-ABRELATEDNAMES;TYPE=spouse:Jane',
            'item3.X-ABLABEL:_$!<Spouse>!$_',
            'item4.X-ABRELATEDNAMES;TYPE=agent:Bob',
            'item4.X-ABLABEL:_$!<Assistant>!$_',
            'item5.X-ABRELATEDNAMES;TYPE=co-worker:Ann',
            'item5.X-ABLABEL:co-worker',
        ] as $line) {
            self::assertContains($line, $lines);
        }

        self::assertStringNotContainsString('KIND', $result->value);
        self::assertDoesNotMatchRegularExpression('/^RELATED/m', $result->value);

        // Read back with the dialect: the same Card, with the labels Apple needs.
        $again = new VCardDecoder(dialects: [new Apple()])->decode($result->value)[0]->value ?? null;
        self::assertEquals($card->relatedTo, array_map(static fn (Relation $relation): Relation => new Relation($relation->relation), $again->relatedTo ?? []));
        self::assertEquals(new PartialDate(null, 4, 12), $again?->anniversaries['b']->date);
        self::assertSame('FR', $again?->addresses['a1']->countryCode);
    }

    public function testIssuesAboutPropertiesTheDialectReplacedAreDropped(): void
    {
        $card = new Card(kind: Card::KIND_GROUP, members: ['urn:uuid:1'], relatedTo: ['Jane' => new Relation(['friend'])]);
        self::assertSame([
            '/kind: vCard 3.0 does not define KIND, wrote it anyway',
            '/relatedTo/Jane: vCard 3.0 does not define RELATED, wrote it anyway',
            '/members: vCard 3.0 does not define MEMBER, wrote it anyway',
        ], array_map(strval(...), new VCardEncoder()->encode($card, VCardVersion::V30)->issues));

        self::assertSame([], array_map(strval(...), new VCardEncoder(dialects: [new Apple()])->encode($card, VCardVersion::V30)->issues));

        // A KIND Apple has no equivalent for stays, and so does the issue.
        self::assertSame([
            '/kind: vCard 3.0 does not define KIND, wrote it anyway',
            '/kind: Apple has no kind "location", kept KIND',
        ], array_map(strval(...), new VCardEncoder(dialects: [new Apple()])->encode(new Card(kind: Card::KIND_LOCATION), VCardVersion::V30)->issues));
    }

    public function testDatesWithoutYearAreWrittenInYear1604InVersion3(): void
    {
        $card = new Card(anniversaries: [
            'b' => new Anniversary(Anniversary::KIND_BIRTH, new PartialDate(null, 4, 12)),
            'w' => new Anniversary(Anniversary::KIND_WEDDING, new PartialDate(null, 6, 12)),
        ]);

        self::assertStringContainsString('JSPROP', new VCardEncoder()->encode($card, VCardVersion::V30)->value);

        $result = new VCardEncoder(dialects: [new Apple()])->encode($card, VCardVersion::V30);

        self::assertSame([], array_map(strval(...), $result->issues));
        self::assertStringNotContainsString('JSPROP', $result->value);
        self::assertStringContainsString("BDAY;PROP-ID=b;X-APPLE-OMIT-YEAR=1604:1604-04-12\r\n", $result->value);
        self::assertStringContainsString("item1.X-ABDATE;PROP-ID=w;X-APPLE-OMIT-YEAR=1604:1604-06-12\r\n", $result->value);
        $again = new VCardDecoder(dialects: [new Apple()])->decode($result->value)[0]->value ?? null;
        self::assertEquals($card->anniversaries['b'], $again?->anniversaries['b']);
        self::assertEquals(new PartialDate(null, 6, 12), $again?->anniversaries['w']->date);

        // With a place, the JSPROP is still needed.
        $withPlace = new Card(anniversaries: ['b' => new Anniversary(Anniversary::KIND_BIRTH, new PartialDate(null, 4, 12), new Address(full: 'Paris'))]);
        $result = new VCardEncoder(dialects: [new Apple()])->encode($withPlace, VCardVersion::V30);
        self::assertStringContainsString('JSPROP', $result->value);
        self::assertSame(['/anniversaries/b: vCard 3.0 has no partial dates, wrote the anniversary as JSPROP'], array_map(strval(...), $result->issues));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function appleExports(): iterable
    {
        foreach (['apple.vcf', 'google.vcf', 'google-full.vcf', 'ios-full.vcf', 'ios-complex-types.vcf', 'social.vcf', 'multiple-cards.vcf'] as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('appleExports')]
    public function testAppleExportsSurviveARoundTripThroughTheDialect(string $file): void
    {
        $decoder = new VCardDecoder(dialects: [new Apple()]);
        foreach ($decoder->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/'.$file)) as $result) {
            $vCard = new VCardEncoder(validate: false, dialects: [new Apple()])->encode($result->value, VCardVersion::V30)->value;

            self::assertEquals($result->value, $decoder->decode($vCard)[0]->value ?? null);
        }
    }

    private function read(string $properties): Card
    {
        $results = new VCardDecoder(dialects: [new Apple()])->decode("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\n".$properties."END:VCARD\r\n");
        self::assertSame([], array_map(strval(...), $results[0]->issues ?? []));

        return $results[0]->value;
    }
}
