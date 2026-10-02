<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;

/**
 * The JSContact to vCard examples of RFC 9555 (section 3), in both directions.
 */
final class Rfc9555JsonToVCardTest extends TestCase
{
    public function testFigure48UnknownProperty(): void
    {
        $vCard = $this->encode('{"someUnknownProperty": true}');

        self::assertSame(['JSPTR' => 'someUnknownProperty'], $this->parameters($vCard, 'JSPROP'));
        self::assertSame('true', $this->property($vCard, 'JSPROP')->__toString());
        $this->assertRoundTrip('{"someUnknownProperty": true}');
    }

    public function testFigure49VendorSpecificProperty(): void
    {
        $vCard = $this->encode('{"example.com:foo": {"bar": 1234}}');

        self::assertSame(['JSPTR' => 'example.com:foo'], $this->parameters($vCard, 'JSPROP'));
        self::assertSame('{"bar":1234}', $this->property($vCard, 'JSPROP')->__toString());
        self::assertStringContainsString('JSPROP;JSPTR="example.com:foo":{"bar":1234}', $vCard->serialize());
        $this->assertRoundTrip('{"example.com:foo": {"bar": 1234}}');
    }

    /**
     * The RFC uses "example.com:foo/bar", although the "v-extension" ABNF of RFC 9553
     * forbids "/" in vendor-specific property names: the Card is not valid, so only its
     * conversion to vCard is checked.
     */
    public function testFigure50NestedVendorSpecificProperty(): void
    {
        $card = new JsonDecoder()->decode('{"@type": "Card", "version": "2.0", "phones": {"phone1": {"number": "tel:+33-01-23-45-67"}}}')->value;
        $phone = $card->phones['phone1'] ?? self::fail('No phone.');
        $card = new Card(phones: ['phone1' => new \Rondeto\JSContact\Model\Phone($phone->number, extra: ['example.com:foo/bar' => 'tux hux'])]);

        $vCard = new VCardEncoder(validate: false)->convert($card)->value;

        self::assertSame(['JSPTR' => 'phones/phone1/example.com:foo~1bar'], $this->parameters($vCard, 'JSPROP'));
        self::assertSame('"tux hux"', $this->property($vCard, 'JSPROP')->__toString());
    }

    public function testFigure51SecondaryPositionalIndex(): void
    {
        $json = '{"name": {"components": [{"kind": "given", "value": "Jane"}, {"kind": "surname", "value": "Doe"}], "isOrdered": true}}';
        $vCard = $this->encode($json);

        self::assertSame(['JSCOMPS' => ';1;0'], $this->parameters($vCard, 'N'));
        self::assertSame(['Doe', 'Jane', '', '', '', '', ''], $this->property($vCard, 'N')->getParts());
        self::assertSame(['DERIVED' => 'TRUE'], $this->parameters($vCard, 'FN'));
        self::assertSame('Jane Doe', $this->property($vCard, 'FN')->__toString());
        $this->assertRoundTrip($json);
    }

    public function testFigure52PositionalEntries(): void
    {
        $json = '{"name": {"components": [
            {"kind": "given", "value": "John"},
            {"kind": "given2", "value": "Philip"},
            {"kind": "given2", "value": "Paul"},
            {"kind": "surname", "value": "Stevenson"},
            {"kind": "generation", "value": "Jr."},
            {"kind": "credential", "value": "M.D."}
        ], "isOrdered": true}}';
        $vCard = $this->encode($json);

        self::assertSame(['JSCOMPS' => ';1;2;2,1;0;6;4,1'], $this->parameters($vCard, 'N'));
        self::assertStringContainsString('Stevenson;John;Philip,Paul;;Jr.,M.D.;;Jr.', str_replace("\r\n ", '', $vCard->serialize()));
        $this->assertRoundTrip($json);
        $this->assertDecodesTo(
            "N;JSCOMPS=\";1;2;2,1;0;6;4,1\":Stevenson;John;Philip,Paul;;Jr.,M.D.;;Jr.\r\n",
            $json,
        );
    }

    /**
     * With the correction of erratum 8786: the street number is the 11th component and
     * the street name the 12th, as RFC 9554 defines.
     */
    public function testFigure53SeparatorEntries(): void
    {
        $json = '{"addresses": {"a1": {"components": [
            {"kind": "number", "value": "54321"},
            {"kind": "separator", "value": " "},
            {"kind": "name", "value": "Oak St"},
            {"kind": "locality", "value": "Reston"}
        ], "defaultSeparator": ", ", "isOrdered": true}}}';
        $vCard = $this->encode($json);

        self::assertSame(['JSCOMPS' => 's,\, ;10;s, ;11;3', 'PROP-ID' => 'a1'], $this->parameters($vCard, 'ADR'));
        self::assertSame(['', '', '54321 Oak St', 'Reston', '', '', '', '', '', '', '54321', 'Oak St', '', '', '', '', '', ''], $this->property($vCard, 'ADR')->getParts());
        $this->assertRoundTrip($json);
        $this->assertDecodesTo(
            "ADR;PROP-ID=a1;JSCOMPS=\"s,\\, ;10;s, ;11;3\":;;54321 Oak St;Reston;;;;;;;54321;Oak St;;;;;;\r\n",
            $json,
        );
    }

    private function encode(string $json): VCard
    {
        $result = new VCardEncoder()->convert($this->card($json));
        self::assertSame([], array_map(strval(...), $result->issues));

        return $result->value;
    }

    /**
     * The Card survives a conversion to vCard text and back.
     */
    private function assertRoundTrip(string $json): void
    {
        $card = $this->card($json);
        $results = new VCardDecoder()->decode(new VCardEncoder()->encode($card)->value);

        self::assertSame([], array_map(strval(...), $results[0]->issues ?? []));
        self::assertJsonStringEqualsJsonString(new JsonEncoder()->encode($card), new JsonEncoder()->encode($results[0]->value ?? new Card()));
    }

    private function assertDecodesTo(string $properties, string $json): void
    {
        $results = new VCardDecoder()->decode("BEGIN:VCARD\r\nVERSION:4.0\r\n".$properties."END:VCARD\r\n");

        self::assertSame([], array_map(strval(...), $results[0]->issues ?? []));
        self::assertJsonStringEqualsJsonString(new JsonEncoder()->encode($this->card($json)), new JsonEncoder()->encode($results[0]->value ?? new Card()));
    }

    private function card(string $json): Card
    {
        $properties = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($properties);

        return new JsonDecoder(strict: true)->decode(json_encode(['@type' => 'Card', 'version' => '2.0'] + $properties, \JSON_THROW_ON_ERROR))->value;
    }

    private function property(VCard $vCard, string $name): Property
    {
        $properties = array_values($vCard->select($name));
        self::assertInstanceOf(Property::class, $properties[0] ?? null);

        return $properties[0];
    }

    /**
     * @return array<string, string>
     */
    private function parameters(VCard $vCard, string $name): array
    {
        $parameters = [];
        foreach ($this->property($vCard, $name)->parameters() as $parameterName => $parameter) {
            $parameters[(string) $parameterName] = (string) $parameter->getValue();
        }

        return $parameters;
    }
}
