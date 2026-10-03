<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\Localization;

use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Localization\Localizer;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Rondeto\JSContact\Model\PatchObject;
use Rondeto\JSContact\Model\Title;

final class LocalizerTest extends TestCase
{
    /**
     * RFC 9553, Figure 39.
     */
    public function testATopLevelPropertyIsReplaced(): void
    {
        $card = $this->figure(39);

        $result = new Localizer()->localize($card, 'UK-cyrl');

        self::assertSame([], $result->issues);
        self::assertSame('uk-Cyrl', $result->value->language);
        self::assertSame('Иван', $result->value->name?->components[1]->value);
        self::assertSame([], $result->value->localizations);
    }

    /**
     * RFC 9553, Figure 40.
     */
    public function testANestedPropertyIsPatched(): void
    {
        $result = new Localizer()->localize($this->figure(40), 'es');

        self::assertEquals(new Title('escritor', Title::KIND_TITLE), $result->value->titles['t1'] ?? null);
        self::assertSame('Gabriel García Márquez', $result->value->name?->full);
    }

    /**
     * RFC 9553, Figure 20: patches into an array.
     */
    public function testPatchesGoIntoArrays(): void
    {
        $result = new Localizer()->localize($this->figure(20), 'yue');

        self::assertSame('jyut', $result->value->name?->phoneticSystem);
        self::assertNotNull($result->value->name);
        self::assertSame(['syun1', 'zung1saan1', 'man4', 'jat6sin1'], array_map(static fn (NameComponent $component): ?string => $component->phonetic, $result->value->name->components));
    }

    public function testWithoutLocalizationTheCardIsUnchanged(): void
    {
        $card = $this->figure(40);

        self::assertSame('novelist', new Localizer()->localize($card, 'de')->value->titles['t1']->name ?? null);
    }

    public function testAnInvalidLocalizationIsNotApplied(): void
    {
        $card = new Card(name: new Name(full: 'Jane'), localizations: ['fr' => new PatchObject(['name/full' => 'Jeanne', 'titles/t1/name' => 'Patronne'])]);

        $result = new Localizer()->localize($card, 'fr');

        self::assertSame('Jane', $result->value->name?->full);
        self::assertSame(['/localizations/fr: did not apply the localization: "titles/t1/name" points into a value that does not exist'], array_map(strval(...), $result->issues));
    }

    public function testItBuildsTheLocalizationOfACard(): void
    {
        $card = new Card(name: new Name([new NameComponent('given', 'John'), new NameComponent('surname', 'Doe')]), titles: ['t1' => new Title('Boss')]);
        $french = new Card(language: 'fr', name: new Name([new NameComponent('given', 'Jean'), new NameComponent('surname', 'Doe')]), titles: ['t1' => new Title('Patron')]);

        self::assertEquals(
            new PatchObject(['name/components/0/value' => 'Jean', 'titles/t1/name' => 'Patron']),
            new Localizer()->localization($card, $french),
        );
    }

    private function figure(int $number): Card
    {
        $figure = json_decode((string) file_get_contents(\sprintf('%s/../Fixtures/Rfc9553/figure-%02d.json', __DIR__, $number)), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($figure);

        return new JsonDecoder(strict: true)->decode(json_encode(['version' => '2.0'] + $figure + ['@type' => 'Card'], \JSON_THROW_ON_ERROR))->value;
    }
}
