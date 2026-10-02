<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Json;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Json\DecodingException;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Model\Anniversary;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\EmailAddress;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\PartialDate;
use Rondeto\JSContact\Model\Phone;
use Rondeto\JSContact\Model\Timestamp;
use Rondeto\JSContact\Model\VCardProperty;
use Rondeto\JSContact\Validation\InvalidCardException;

final class JsonDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notACard(): iterable
    {
        yield 'invalid JSON' => ['{"@type": "Card",'];
        yield 'not an object' => ['["Card"]'];
        yield 'another type' => ['{"@type": "CardGroup", "version": "2.0"}'];
    }

    #[DataProvider('notACard')]
    public function testItRefusesWhatCannotBeACard(string $json): void
    {
        $this->expectException(DecodingException::class);

        new JsonDecoder()->decode($json);
    }

    public function testAVersion2CardNeedsNoUid(): void
    {
        $result = $this->decode([]);

        self::assertEquals(new Card(), $result->value);
        self::assertSame([], $result->issues);
    }

    public function testAVersion1CardWithoutUidIsReadWithAnIssue(): void
    {
        $this->assertIssues(['/uid: missing mandatory property in a version 1.0 Card'], $this->decode(['version' => '1.0']));
    }

    public function testAMissingTypeOrVersionIsReadWithAnIssue(): void
    {
        $result = new JsonDecoder()->decode('{"prodId": "Test"}');

        self::assertSame('Test', $result->value->prodId);
        $this->assertIssues([
            '/@type: missing mandatory property, read the object as a Card',
            '/version: missing mandatory property, read the Card as version 2.0',
        ], $result);
    }

    public function testAnUnsupportedVersionIsReadWithAnIssue(): void
    {
        $this->assertIssues(['/version: unsupported version "3.0", read the Card as version 2.0'], $this->decode(['version' => '3.0']));
    }

    public function testValuesOfTheWrongTypeAreSkipped(): void
    {
        $result = $this->decode([
            'prodId' => 42,
            'emails' => [
                'e1' => ['address' => 'a@example.com', 'pref' => 0, 'label' => ['home']],
                'e2' => ['address' => 'b@example.com', 'pref' => '1', 'contexts' => ['work' => false, 'private' => true]],
            ],
            'name' => ['components' => [['kind' => 'given', 'value' => 'Jane'], 'Doe'], 'isOrdered' => 'yes'],
        ]);

        self::assertNull($result->value->prodId);
        self::assertEquals([
            'e1' => new EmailAddress('a@example.com'),
            'e2' => new EmailAddress('b@example.com', contexts: ['private']),
        ], $result->value->emails);
        self::assertEquals(new Name(components: [new NameComponent('given', 'Jane')]), $result->value->name);
        $this->assertIssues([
            '/prodId: expected a string, ignored the value',
            '/name/components/1: expected an object, ignored the entry',
            '/name/isOrdered: expected a boolean, ignored the value',
            '/emails/e1/pref: expected an integer from 1 to 100, ignored the value',
            '/emails/e1/label: expected a string, ignored the value',
            '/emails/e2/contexts/work: set values must be true, ignored the entry',
            '/emails/e2/pref: expected an integer from 1 to 100, ignored the value',
        ], $result);
    }

    public function testEntriesMissingAMandatoryPropertyAreSkipped(): void
    {
        $result = $this->decode([
            'phones' => ['p1' => ['features' => ['voice' => true]], 'p2' => ['number' => 'tel:+33612345678']],
            'name' => ['components' => [['value' => 'Jane'], ['kind' => 'surname']]],
        ]);

        self::assertEquals(['p2' => new Phone('tel:+33612345678')], $result->value->phones);
        self::assertEquals(new Name(), $result->value->name);
        $this->assertIssues([
            '/name/components/0/kind: missing mandatory property',
            '/name/components/0: ignored the component',
            '/name/components/1/value: missing mandatory property',
            '/name/components/1: ignored the component',
            '/phones/p1/number: missing mandatory property',
            '/phones/p1: ignored the entry',
            '/name: a name needs components, full, or both',
        ], $result);
    }

    public function testEntriesWithAnInvalidIdOrTypeAreSkipped(): void
    {
        $result = $this->decode(['emails' => [
            'not valid!' => ['address' => 'a@example.com'],
            'e1' => ['@type' => 'Phone', 'address' => 'b@example.com'],
            'e2' => ['@type' => 'emailaddress', 'address' => 'c@example.com'],
        ]]);

        self::assertEquals(['e2' => new EmailAddress('c@example.com')], $result->value->emails);
        $this->assertIssues([
            '/emails/not valid!: not a valid Id, ignored the entry',
            '/emails/e1/@type: expected "EmailAddress", ignored the object',
            '/emails/e1: ignored the entry',
            '/emails/e2/@type: read "emailaddress" as "EmailAddress"',
        ], $result);
    }

    public function testNumericIdsAreKept(): void
    {
        $result = $this->decode(['emails' => ['1' => ['address' => 'a@example.com']]]);

        self::assertEquals(new EmailAddress('a@example.com'), $result->value->emails['1'] ?? null);
    }

    public function testCaseVariantsOfRegisteredValuesAreCorrected(): void
    {
        $result = $this->decode([
            'kind' => 'Individual',
            'phones' => ['p1' => ['number' => 'tel:+1', 'features' => ['Voice' => true], 'contexts' => ['WORK' => true]]],
        ]);

        self::assertSame('individual', $result->value->kind);
        self::assertEquals(['p1' => new Phone('tel:+1', features: ['voice'], contexts: ['work'])], $result->value->phones);
        $this->assertIssues([
            '/kind: read "Individual" as "individual"',
            '/phones/p1/features/Voice: read "Voice" as "voice"',
            '/phones/p1/contexts/WORK: read "WORK" as "work"',
        ], $result);
    }

    public function testUnknownValuesAreKept(): void
    {
        $result = $this->decode(['kind' => 'robot', 'phones' => ['p1' => ['number' => 'tel:+1', 'features' => ['hologram' => true]]]]);

        self::assertSame('robot', $result->value->kind);
        self::assertSame(['hologram'], $result->value->phones['p1']->features ?? null);
        self::assertSame([], $result->issues);
    }

    public function testDateTimesAreConvertedToUtc(): void
    {
        $result = $this->decode(['created' => '2022-09-30t16:35:10.500+02:00', 'updated' => 'yesterday']);

        self::assertEquals(new \DateTimeImmutable('2022-09-30T14:35:10.5Z'), $result->value->created);
        self::assertNull($result->value->updated);
        $this->assertIssues([
            '/created: read "2022-09-30t16:35:10.500+02:00" as "2022-09-30T14:35:10.5Z"',
            '/updated: "yesterday" is not a date-time, ignored the value',
        ], $result);
    }

    public function testUnknownAndVendorPropertiesAreKept(): void
    {
        $result = $this->decode([
            'futureProperty' => ['a' => 1],
            'example.com:flag' => true,
            'localizations' => ['fr' => ['name/full' => 'ACME']],
            'emails' => ['e1' => ['address' => 'a@example.com', 'example.com:verified' => true]],
        ]);

        self::assertEquals([
            'futureProperty' => (object) ['a' => 1],
            'example.com:flag' => true,
            'localizations' => (object) ['fr' => (object) ['name/full' => 'ACME']],
        ], $result->value->extra);
        self::assertSame(['example.com:verified' => true], $result->value->emails['e1']->extra ?? null);
        self::assertSame([], $result->issues);
    }

    public function testInvalidPropertyNamesAreDropped(): void
    {
        $result = $this->decode(['Emails' => [], 'Localizations' => [], 'extra' => 1, 'not-a-name' => 1]);

        self::assertSame([], $result->value->extra);
        $this->assertIssues([
            '/Emails: property names are case-sensitive, ignored the property',
            '/Localizations: property names are case-sensitive, ignored the property',
            '/extra: "extra" is a reserved property name, ignored the property',
            '/not-a-name: not a valid property name, ignored the property',
        ], $result);
    }

    public function testRuleViolationsAreReportedButKept(): void
    {
        $result = $this->decode(['members' => ['urn:uuid:1' => true], 'emails' => ['e1' => ['address' => 'not an email']]]);

        self::assertSame(['urn:uuid:1'], $result->value->members);
        self::assertSame('not an email', $result->value->emails['e1']->address ?? null);
        $this->assertIssues([
            '/members: members can only be set when kind is "group"',
            '/emails/e1/address: "not an email" is not an email address',
        ], $result);
    }

    public function testVCardPropertiesAreRead(): void
    {
        $result = $this->decode([
            'vCardProps' => [['x-foo', ['X-Bar' => 'Hello', 'group' => 'item1'], 'unknown', 'World!'], ['bad']],
            'phones' => ['p1' => ['number' => 'tel:+1', 'vCardName' => 'tel', 'vCardParams' => ['group' => 'item1', 'x-list' => ['a', 'b'], 'x-bad' => 1]]],
        ]);

        self::assertEquals([new VCardProperty('x-foo', ['x-bar' => 'Hello', 'group' => 'item1'], 'unknown', ['World!'])], $result->value->vCardProps);
        self::assertEquals(new Phone('tel:+1', vCardName: 'tel', vCardParams: ['group' => 'item1', 'x-list' => ['a', 'b']]), $result->value->phones['p1'] ?? null);
        $this->assertIssues([
            '/phones/p1/vCardParams/x-bad: expected a string or a list of strings, ignored the parameter',
            '/vCardProps/1: not a jCard property, ignored the entry',
        ], $result);
    }

    public function testAnniversaryDates(): void
    {
        $result = $this->decode(['anniversaries' => [
            'a1' => ['kind' => 'birth', 'date' => ['@type' => 'timestamp', 'utc' => '2019-10-15T23:10:00Z']],
            'a2' => ['kind' => 'death', 'date' => ['year' => 1953, 'month' => '4']],
            'a3' => ['kind' => 'wedding'],
        ]]);

        self::assertEquals([
            'a1' => new Anniversary('birth', new Timestamp(new \DateTimeImmutable('2019-10-15T23:10:00Z'))),
            'a2' => new Anniversary('death', new PartialDate(1953)),
        ], $result->value->anniversaries);
        $this->assertIssues([
            '/anniversaries/a1/date/@type: read "timestamp" as "Timestamp"',
            '/anniversaries/a2/date/month: expected an unsigned integer, ignored the value',
            '/anniversaries/a3/date: missing mandatory property',
            '/anniversaries/a3: ignored the entry',
        ], $result);
    }

    public function testStrictModeRefusesACardWithAnyIssue(): void
    {
        $json = '{"@type": "Card", "version": "2.0", "kind": "Group", "emails": {"e1": {"address": "a@example.com", "pref": 0}}}';

        try {
            new JsonDecoder(strict: true)->decode($json);
            self::fail('An invalid card was read in strict mode.');
        } catch (InvalidCardException $e) {
            self::assertSame([
                '/kind: read "Group" as "group"',
                '/emails/e1/pref: expected an integer from 1 to 100, ignored the value',
            ], array_map(strval(...), $e->issues));
        }
    }

    public function testStrictModeReadsAValidCard(): void
    {
        $result = new JsonDecoder(strict: true)->decode('{"@type": "Card", "version": "2.0", "uid": "urn:uuid:1"}');

        self::assertEquals(new Card(uid: 'urn:uuid:1'), $result->value);
        self::assertFalse($result->hasIssues());
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return Result<Card>
     */
    private function decode(array $properties): Result
    {
        return new JsonDecoder()->decode(json_encode($properties + ['@type' => 'Card', 'version' => '2.0'], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string>  $expected
     * @param Result<mixed> $result
     */
    private function assertIssues(array $expected, Result $result): void
    {
        self::assertSame($expected, array_map(strval(...), $result->issues));
    }
}
