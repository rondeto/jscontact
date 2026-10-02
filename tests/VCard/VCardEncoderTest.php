<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\InvalidCardException;
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

        $result = new VCardEncoder()->encode($card, VCardVersion::V30);

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
            extra: ['organizations' => ['o1' => ['name' => 'ACME']]],
        );

        $result = new VCardEncoder()->encode($card);

        self::assertStringContainsString('JSPROP;JSPTR=organizations:{"o1":{"name":"ACME"}}', $result->value);
        self::assertSame([
            '/phones/p1/features/hologram: vCard has no TEL type for this feature, left it out',
            '/phones/p1/contexts/example.com:car: vCard has no TYPE for this context, left it out',
            '/organizations: not converted to vCard properties yet, wrote it as JSPROP',
        ], array_map(strval(...), $result->issues));
    }

    public function testItRefusesAnInvalidCardUnlessValidationIsOff(): void
    {
        $card = new Card(emails: ['e1' => new EmailAddress('a@example.com', pref: 0)]);

        self::assertStringContainsString('EMAIL;PREF=0;PROP-ID=e1:a@example.com', new VCardEncoder(validate: false)->encode($card)->value);

        $this->expectException(InvalidCardException::class);
        new VCardEncoder()->encode($card);
    }
}
