<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Guards the supported sabre/vobject range: every major version the
 * library allows must parse and upgrade vCards the way the converters expect.
 */
final class SabreVObjectTest extends TestCase
{
    public function testItParsesAVCard30AndConvertsItTo40(): void
    {
        $card = Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nEMAIL;TYPE=INTERNET,PREF:jane@example.com\r\nEND:VCARD\r\n");

        self::assertInstanceOf(VCard::class, $card);

        $converted = $card->convert(VCard::VCARD40);

        self::assertSame('4.0', VCard::VCARD40 === $converted->getDocumentType() ? '4.0' : null);

        $emails = $converted->select('EMAIL');
        self::assertCount(1, $emails);
        $email = array_values($emails)[0];
        self::assertInstanceOf(Property::class, $email);
        self::assertSame('jane@example.com', $email->getValue());
        $pref = $email['PREF'];
        self::assertInstanceOf(Parameter::class, $pref);
        self::assertSame('1', $pref->getValue());
    }
}
