<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Cards exported by real address books (see tests/Fixtures/SOURCES.md) convert without
 * loss: converting the Card to vCard and back gives the same Card, and every property of
 * the original vCard is still in the vCard written from the Card.
 */
final class RealCardsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        foreach (glob(__DIR__.'/../Fixtures/CozyVcard/*.vcf') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('files')]
    public function testCardsSurviveARoundTripThroughVCard(string $file): void
    {
        foreach (new VCardDecoder()->decode((string) file_get_contents($file)) as $result) {
            foreach ([VCardVersion::V40, VCardVersion::V30] as $version) {
                $vCard = new VCardEncoder(validate: false)->encode($result->value, $version)->value;
                $again = new VCardDecoder()->decode($vCard);

                self::assertCount(1, $again);
                self::assertEquals($result->value, $again[0]->value, \sprintf('The Card changed through vCard %s.', $version->value));
            }
        }
    }

    #[DataProvider('files')]
    public function testEveryPropertyIsWrittenBack(string $file): void
    {
        $text = (string) file_get_contents($file);
        $cards = new VCardDecoder()->decode($text);
        $originals = preg_split('/(?<=END:VCARD)\r?\n/i', trim($text)) ?: [];

        self::assertCount(\count($cards), $originals);
        foreach ($cards as $index => $result) {
            $written = new VCardEncoder(validate: false)->encode($result->value)->value;

            self::assertGreaterThanOrEqual($this->propertyNames($originals[$index]), $this->propertyNames($written));
        }
    }

    public function testAppleLabelsAndGroups(): void
    {
        $card = $this->card('ios-full.vcf');

        self::assertSame('iCloud', $card->emails['EMAIL-3']->label ?? null);
        self::assertSame(['type' => 'internet', 'group' => 'item1'], $card->emails['EMAIL-3']->vCardParams ?? null);
        self::assertSame(1, $card->emails['EMAIL-1']->pref ?? null);
        self::assertSame(['private'], $card->emails['EMAIL-1']->contexts ?? null);
        self::assertSame(['mobile', 'voice'], $card->phones['PHONE-3']->features ?? null);
        self::assertSame(['type' => 'iphone'], $card->phones['PHONE-3']->vCardParams ?? null);

        $abAdr = array_values(array_filter($card->vCardProps, static fn (\Rondeto\JSContact\Model\VCardProperty $property): bool => 'x-abadr' === $property->name));
        self::assertSame(['group' => 'item5'], $abAdr[0]->parameters ?? null);
        self::assertSame(['fr'], $abAdr[0]->values ?? null);
    }

    public function testQuotedPrintableValuesAreDecoded(): void
    {
        $card = $this->card('android-quotedprintable.vcf');

        self::assertNotNull($card->name);
        self::assertNotSame([], $card->name->components);
        self::assertStringNotContainsString('=C3', json_encode($card->name->components, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    public function testABrokenLineIsReported(): void
    {
        $results = new VCardDecoder()->decode((string) file_get_contents(__DIR__.'/../Fixtures/CozyVcard/google-full.vcf'));

        self::assertContains('/: line 27 is not a vCard property, ignored it', array_map(strval(...), $results[0]->issues ?? []));
    }

    private function card(string $file): Card
    {
        $results = new VCardDecoder()->decode((string) file_get_contents(__DIR__.'/../Fixtures/CozyVcard/'.$file));
        self::assertCount(1, $results);

        return $results[0]->value;
    }

    /**
     * How many times each property occurs, by uppercase name, VERSION aside.
     *
     * @return array<string, int>
     */
    private function propertyNames(string $vCard): array
    {
        $names = [];
        $document = Reader::read($vCard, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        foreach ($document?->children() ?? [] as $property) {
            // sabre reads a broken line as a property named after its first characters.
            if ($property instanceof Property && 'VERSION' !== $property->name && 1 === preg_match('/^[A-Z][A-Z0-9-]*$/i', (string) $property->name)) {
                $names[(string) $property->name] = ($names[(string) $property->name] ?? 0) + 1;
            }
        }

        ksort($names);

        return $names;
    }
}
