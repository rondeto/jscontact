<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Json;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;

/**
 * Every JSON example of RFC 9553 must survive a decode/encode round trip, whether the
 * properties it shows are modeled or kept verbatim.
 *
 * Figures that show properties rather than a whole Card are wrapped in a version 2.0
 * Card. Figures 2 and 5 are ABNF, not JSON.
 */
final class Rfc9553FiguresTest extends TestCase
{
    /**
     * Warnings a figure legitimately triggers, by figure number.
     */
    private const array EXPECTED_WARNINGS = [
        // Figure 20 is a whole Card that omits the mandatory version property.
        20 => ['/version: missing mandatory property, read the Card as version 2.0'],
    ];

    /**
     * @return iterable<string, array{int, \stdClass}>
     */
    public static function figures(): iterable
    {
        foreach (glob(__DIR__.'/../Fixtures/Rfc9553/figure-*.json') ?: [] as $file) {
            $number = (int) substr(basename($file, '.json'), \strlen('figure-'));
            $figure = json_decode((string) file_get_contents($file), false, 512, \JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $figure);

            yield 'Figure '.$number => [$number, $figure];
        }
    }

    #[DataProvider('figures')]
    public function testFigureRoundTrips(int $number, \stdClass $figure): void
    {
        $card = property_exists($figure, '@type') ? clone $figure : (object) (['@type' => 'Card', 'version' => '2.0'] + get_object_vars($figure));

        $result = new JsonDecoder()->decode(json_encode($card, \JSON_THROW_ON_ERROR));

        self::assertSame(self::EXPECTED_WARNINGS[$number] ?? [], array_map(strval(...), $result->warnings));

        $card->version = JsonEncoder::VERSION;
        self::assertSame(
            $this->canonical($card),
            $this->canonical(new JsonEncoder()->normalize($result->value)),
        );
    }

    /**
     * JSON with object keys sorted, so that property order does not matter.
     */
    private function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        if (!$value instanceof \stdClass) {
            return $value;
        }

        $properties = array_map(self::sortKeys(...), get_object_vars($value));
        ksort($properties, \SORT_STRING);

        return (object) $properties;
    }
}
