<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;

/**
 * The vCard to JSContact examples of RFC 9555, for the properties this library converts.
 *
 * Each figure is a vCard excerpt and the JSON it converts to. The excerpt is wrapped in a
 * version 4.0 vCard, the JSON in a version 2.0 Card. See tests/Fixtures/SOURCES.md for
 * the differences with the RFC.
 */
final class Rfc9555FiguresTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function figures(): iterable
    {
        foreach (glob(__DIR__.'/../Fixtures/Rfc9555/figure-*.vcf') ?: [] as $file) {
            $number = (int) substr(basename($file, '.vcf'), \strlen('figure-'));
            $json = (string) file_get_contents(substr($file, 0, -4).'.json');

            yield 'Figure '.$number => [(string) file_get_contents($file), $json];
        }
    }

    #[DataProvider('figures')]
    public function testTheVCardConvertsToTheJson(string $vCard, string $json): void
    {
        $card = $this->decode($vCard);

        self::assertJsonStringEqualsJsonString($this->card($json), new JsonEncoder()->encode($card));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function vCards(): iterable
    {
        foreach (self::figures() as $name => [$vCard]) {
            yield $name => [$vCard];
        }
    }

    #[DataProvider('vCards')]
    public function testTheCardSurvivesARoundTripThroughVCard(string $vCard): void
    {
        $card = $this->decode($vCard);

        $result = new VCardEncoder()->encode($card);
        self::assertSame([], array_map(strval(...), $result->issues));

        $results = new VCardDecoder()->decode($result->value);
        self::assertCount(1, $results);
        self::assertSame([], array_map(strval(...), $results[0]->issues));
        self::assertEquals($card, $results[0]->value);
    }

    private function decode(string $excerpt): Card
    {
        $results = new VCardDecoder()->decode("BEGIN:VCARD\r\nVERSION:4.0\r\n".str_replace("\n", "\r\n", $excerpt)."END:VCARD\r\n");

        self::assertCount(1, $results);
        self::assertSame([], array_map(strval(...), $results[0]->issues));

        return $results[0]->value;
    }

    private function card(string $json): string
    {
        $properties = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($properties);

        return json_encode(['@type' => 'Card', 'version' => '2.0'] + $properties, \JSON_THROW_ON_ERROR);
    }
}
