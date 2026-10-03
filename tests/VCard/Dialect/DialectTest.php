<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard\Dialect;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\Dialect\Dialect;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

final class DialectTest extends TestCase
{
    public function testADialectRewritesTheVCardBeforeItIsRead(): void
    {
        $results = new VCardDecoder(dialects: [new NicknameDialect()])->decode("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jane\r\nX-NICK:Janie\r\nX-NICK:\r\nEND:VCARD\r\n");

        self::assertEquals(['NICK-1' => new Nickname('Janie')], $results[0]->value->nicknames ?? null);
        self::assertSame(['/: X-NICK has no value, left it out'], array_map(strval(...), $results[0]->issues ?? []));
    }

    public function testDialectIssuesFailAStrictRead(): void
    {
        $this->expectException(InvalidCardException::class);

        new VCardDecoder(strict: true, dialects: [new NicknameDialect()])->decode("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jane\r\nX-NICK:\r\nEND:VCARD\r\n");
    }

    public function testConvertLeavesTheGivenVCardUnchanged(): void
    {
        $vCard = Reader::read("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jane\r\nX-NICK:Janie\r\nEND:VCARD\r\n");
        self::assertInstanceOf(VCard::class, $vCard);

        $card = new VCardDecoder(dialects: [new NicknameDialect()])->convert($vCard)->value;

        self::assertCount(1, $card->nicknames);
        self::assertTrue(isset($vCard->{'X-NICK'}));
    }

    public function testADialectRewritesTheVCardOnceWritten(): void
    {
        $result = new VCardEncoder(dialects: [new NicknameDialect()])->encode(new Card(nicknames: ['n1' => new Nickname('Janie')]));

        self::assertStringContainsString("X-NICK:Janie\r\n", $result->value);
        self::assertStringNotContainsString('NICKNAME', $result->value);
        self::assertSame(['/: NICKNAME parameters are left out'], array_map(strval(...), $result->issues));
    }
}

/**
 * A vendor that writes nicknames as X-NICK, without parameters.
 */
final class NicknameDialect implements Dialect
{
    public function read(VCard $vCard): array
    {
        $issues = [];
        foreach ($vCard->select('X-NICK') as $property) {
            if (!$property instanceof Property) {
                continue;
            }

            $vCard->remove($property);
            if ('' === (string) $property) {
                $issues[] = new Issue('', 'X-NICK has no value, left it out');
            } else {
                $vCard->add('NICKNAME', (string) $property);
            }
        }

        return $issues;
    }

    public function write(VCard $vCard): array
    {
        $issues = [];
        foreach ($vCard->select('NICKNAME') as $property) {
            if (!$property instanceof Property) {
                continue;
            }

            $vCard->remove($property);
            $vCard->add('X-NICK', (string) $property);
            if (\count($property->parameters()) > 0) {
                $issues[] = new Issue('', 'NICKNAME parameters are left out');
            }
        }

        return $issues;
    }
}
