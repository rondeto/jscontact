<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Model;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Context;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;

final class EditingTest extends TestCase
{
    public function testAnImportedCardIsEditedInPlace(): void
    {
        $vCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jane\r\nEMAIL:old@example.com\r\nNOTE:To remove\r\nEND:VCARD\r\n";
        $card = new VCardDecoder()->decode($vCard)[0]->value;
        self::assertNotNull($card->name);

        $card->name->full = 'Jane Doe';
        $card->emails['EMAIL-1']->address = 'jane@example.com';
        $card->phones['work'] = new Phone('+33 1 23 45 67 89', contexts: [Context::WORK]);
        unset($card->notes['NOTE-1']);

        self::assertSame(
            "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jane Doe\r\nEMAIL;PROP-ID=EMAIL-1:jane@example.com\r\nTEL;PROP-ID=work;TYPE=work:+33 1 23 45 67 89\r\nEND:VCARD\r\n",
            new VCardEncoder()->encode($card)->value,
        );

        self::assertJsonStringEqualsJsonString(
            '{"@type": "Card", "version": "2.0", "name": {"full": "Jane Doe"}, "emails": {"EMAIL-1": {"address": "jane@example.com"}}, "phones": {"work": {"number": "+33 1 23 45 67 89", "contexts": {"work": true}}}}',
            new JsonEncoder()->encode($card),
        );
    }
}
