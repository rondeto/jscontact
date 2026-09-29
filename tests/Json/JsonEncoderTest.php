<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Json;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Note;
use Rondeto\JSContact\Validation\InvalidCardException;

final class JsonEncoderTest extends TestCase
{
    public function testItWritesVersion2AndLeavesUnsetPropertiesOut(): void
    {
        self::assertSame('{"@type":"Card","version":"2.0"}', new JsonEncoder()->encode(new Card()));
    }

    public function testMapsWithNumericIdsAreWrittenAsObjects(): void
    {
        $json = new JsonEncoder()->encode(new Card(emails: ['0' => new EmailAddress('a@example.com')]));

        self::assertSame('{"@type":"Card","version":"2.0","emails":{"0":{"address":"a@example.com"}}}', $json);
    }

    public function testDateTimesAreWrittenAsUtcDateTimes(): void
    {
        $card = new Card(
            created: new \DateTimeImmutable('2010-10-10T12:10:10.003+02:00'),
            updated: new \DateTimeImmutable('2010-10-10T10:10:10.000Z'),
            notes: ['n1' => new Note('Hi', created: new \DateTimeImmutable('2010-10-10T10:10:10.120Z'))],
        );

        self::assertSame(
            '{"@type":"Card","version":"2.0","created":"2010-10-10T10:10:10.003Z","updated":"2010-10-10T10:10:10Z","notes":{"n1":{"note":"Hi","created":"2010-10-10T10:10:10.12Z"}}}',
            new JsonEncoder()->encode($card),
        );
    }

    public function testExtraPropertiesAreWrittenVerbatim(): void
    {
        $card = new Card(extra: ['example.com:empty' => new \stdClass(), 'example.com:list' => []]);

        self::assertSame('{"@type":"Card","version":"2.0","example.com:empty":{},"example.com:list":[]}', new JsonEncoder()->encode($card));
    }

    public function testItRefusesAnInvalidCard(): void
    {
        try {
            new JsonEncoder()->encode(new Card(emails: ['e1' => new EmailAddress('a@example.com', pref: 0)]));
            self::fail('An invalid card was encoded.');
        } catch (InvalidCardException $e) {
            self::assertSame(['/emails/e1/pref: must be between 1 and 100'], array_map(strval(...), $e->violations));
        }
    }

    public function testValidationCanBeTurnedOff(): void
    {
        $json = new JsonEncoder(validate: false)->encode(new Card(kind: Card::KIND_ORG, members: ['urn:uuid:1']));

        self::assertSame('{"@type":"Card","version":"2.0","kind":"org","members":{"urn:uuid:1":true}}', $json);
    }
}
