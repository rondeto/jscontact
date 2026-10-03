<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard\Dialect;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Media;
use Rondeto\JSContact\Model\Nickname;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\Dialect\Dialect;
use Rondeto\JSContact\VCard\Target;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;
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
        $result = new VCardEncoder()->encode(new Card(nicknames: ['n1' => new Nickname('Janie')]), new Target(dialects: [new NicknameDialect()]));

        self::assertStringContainsString("X-NICK:Janie\r\n", $result->value);
        self::assertStringNotContainsString('NICKNAME', $result->value);
        self::assertSame(['/: NICKNAME parameters are left out'], array_map(strval(...), $result->issues));
    }

    public function testOnlyIssuesAboutHowAPropertyIsWrittenGoWithIt(): void
    {
        $card = new Card(
            kind: Card::KIND_GROUP,
            emails: ['e1' => new EmailAddress('a@example.com', contexts: ['example.com:car'])],
            media: ['m1' => new Media('https://example.com/a.jpg', Media::KIND_PHOTO), 'm2' => new Media('https://example.com/a.mp4', 'example.com:video')],
        );

        $issues = static fn (Target $target): array => array_map(strval(...), new VCardEncoder()->encode($card, $target)->issues);
        self::assertSame([
            '/kind: vCard 3.0 does not define KIND, wrote it anyway',
            '/emails/e1/contexts/example.com:car: vCard has no TYPE for this context, left it out',
            '/media/m2: vCard has no media of kind "example.com:video", wrote it as JSPROP',
        ], $issues(new Target(VCardVersion::V30)));

        // A dialect replacing every property: the context is still left out.
        self::assertSame([
            '/emails/e1/contexts/example.com:car: vCard has no TYPE for this context, left it out',
        ], $issues(new Target(VCardVersion::V30, [new ReplacingDialect()])));
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

/**
 * A vendor that writes every property again, the same.
 */
final class ReplacingDialect implements Dialect
{
    public function read(VCard $vCard): array
    {
        return [];
    }

    public function write(VCard $vCard): array
    {
        foreach ($vCard->children() as $property) {
            if ($property instanceof Property && 'VERSION' !== $property->name) {
                $vCard->remove($property);
                $vCard->add(clone $property);
            }
        }

        return [];
    }
}
