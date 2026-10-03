<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard\Dialect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Relation;
use Rondeto\JSContact\VCard\Dialect\Android;
use Rondeto\JSContact\VCard\Dialect\Apple;
use Rondeto\JSContact\VCard\Dialect\LegacyMessaging;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

final class AndroidTest extends TestCase
{
    public function testAnAndroidExportConvertsToTypedProperties(): void
    {
        $result = new VCardDecoder(dialects: [new Android()])->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/android-full.vcf'))[0];
        $card = $result->value;

        self::assertSame([], array_map(strval(...), $result->issues));
        self::assertEquals(new Relation(['parent'], vCardParams: ['x-android-type' => '8']), $card->relatedTo['Mother'] ?? null);
        self::assertEquals(new Relation(['kin'], vCardParams: ['x-android-type' => '12']), $card->relatedTo['Relative'] ?? null);
        self::assertEquals(new Relation([], vCardParams: ['x-android-type' => '7']), $card->relatedTo['Manager'] ?? null);
        self::assertEquals(new Anniversary(Anniversary::KIND_WEDDING, new PartialDate(2015, 3, 4)), $card->anniversaries['ANNIVERSARY-2'] ?? null);
        self::assertContains('Pseudo', array_map(static fn (Nickname $nickname): string => $nickname->name, $card->nicknames));

        // Other events have no JSContact kind: they stay verbatim.
        self::assertCount(2, array_filter($card->vCardProps, static fn (\Rondeto\JSContact\Model\VCardProperty $property): bool => 'x-android-custom' === $property->name));
    }

    public function testAQuotedPrintableRowIsRead(): void
    {
        $card = new VCardDecoder(dialects: [new Android()])->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/android-quotedprintable.vcf'))[0]->value ?? null;

        self::assertSame(['Cozypouet'], array_keys($card->relatedTo ?? []));
    }

    public function testACustomRelationNamedAfterARelatedTypeHasThatType(): void
    {
        $card = new VCardDecoder(dialects: [new Android()])->decode("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\nX-ANDROID-CUSTOM:vnd.android.cursor.item/relation;Ann;0;Colleague;;;;;;;;;;;;\r\nEND:VCARD\r\n")[0]->value ?? null;

        self::assertEquals(new Relation(['colleague'], vCardParams: ['x-android-type' => '0', 'x-android-label' => 'Colleague']), $card?->relatedTo['Ann']);
    }

    public function testACardIsWrittenAsAndroidReadsIt(): void
    {
        $card = new Card(
            anniversaries: [
                'b' => new Anniversary(Anniversary::KIND_BIRTH, new PartialDate(null, 4, 12)),
                'w' => new Anniversary(Anniversary::KIND_WEDDING, new PartialDate(null, 6, 12)),
                'w2' => new Anniversary(Anniversary::KIND_WEDDING, new PartialDate(2010, 6, 12)),
            ],
            relatedTo: ['Jane' => new Relation(['spouse']), 'Ann' => new Relation(['co-worker']), 'Bob' => new Relation([])],
        );

        $result = new VCardEncoder(dialects: [new Android()])->encode($card, VCardVersion::V30);

        self::assertSame([], array_map(strval(...), $result->issues));
        foreach ([
            'BDAY;PROP-ID=b:--04-12',
            'X-ANDROID-CUSTOM;PROP-ID=w:vnd.android.cursor.item/contact_event;--06-12;1;;;;;;;;;;;;;',
            'X-ANDROID-CUSTOM;PROP-ID=w2:vnd.android.cursor.item/contact_event;2010-06-12;1;;;;;;;;;;;;;',
            'X-ANDROID-CUSTOM:vnd.android.cursor.item/relation;Jane;14;;;;;;;;;;;;;',
            'X-ANDROID-CUSTOM:vnd.android.cursor.item/relation;Ann;0;co-worker;;;;;;;;;;;;',
            'X-ANDROID-CUSTOM:vnd.android.cursor.item/relation;Bob;0;;;;;;;;;;;;;',
        ] as $line) {
            self::assertStringContainsString($line."\r\n", str_replace("\r\n ", '', $result->value));
        }

        $again = new VCardDecoder(dialects: [new Android()])->decode($result->value)[0]->value ?? null;
        self::assertNotNull($again);
        self::assertEquals($card->anniversaries, $again->anniversaries);
        self::assertSame(['spouse'], $again->relatedTo['Jane']->relation);
        self::assertSame(['co-worker'], $again->relatedTo['Ann']->relation);
    }

    /**
     * @return iterable<string, array{string, list<\Rondeto\JSContact\VCard\Dialect\Dialect>}>
     */
    public static function exports(): iterable
    {
        foreach (['android.vcf', 'android-full.vcf', 'android-quotedprintable.vcf'] as $file) {
            yield $file => [$file, [new Android(), new LegacyMessaging()]];
        }

        foreach (['apple.vcf', 'google-full.vcf', 'ios-full.vcf'] as $file) {
            yield $file => [$file, [new Apple(), new LegacyMessaging()]];
        }
    }

    /**
     * Reading with every dialect, writing with those of the address book the vCard is for.
     *
     * @param list<\Rondeto\JSContact\VCard\Dialect\Dialect> $dialects
     */
    #[DataProvider('exports')]
    public function testExportsSurviveARoundTripThroughTheirDialects(string $file, array $dialects): void
    {
        $decoder = new VCardDecoder(dialects: [new Apple(), new Android(), new LegacyMessaging()]);
        foreach ($decoder->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/'.$file)) as $result) {
            $vCard = new VCardEncoder(validate: false, dialects: $dialects)->encode($result->value, VCardVersion::V30)->value;

            self::assertEquals($result->value, $decoder->decode($vCard)[0]->value ?? null);
        }
    }
}
