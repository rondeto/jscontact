<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Localization;

use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Localization\Internal\Diff;
use Rondeto\JSContact\Localization\Internal\Patch;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\PatchObject;

/**
 * Localizes Cards, and builds their localizations (RFC 9553, section 2.7.1).
 */
final readonly class Localizer
{
    /**
     * The Card in a language: a copy without localizations, with the PatchObject of that
     * language applied and the language set. Without a localization in that language, the
     * copy is returned unchanged. Language tags are compared case-insensitively.
     *
     * @return Result<Card> The issues say why the localization could not be applied, if so
     */
    public function localize(Card $card, string $language): Result
    {
        $json = $this->json($card);
        $patch = null;
        foreach ($card->localizations as $tag => $localization) {
            if (0 === strcasecmp((string) $tag, $language)) {
                [$language, $patch] = [(string) $tag, $localization];
            }
        }

        if (null === $patch) {
            return new Result($this->card($json)->value);
        }

        // A deep copy: patches change nested objects.
        $localized = json_decode(json_encode($json, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        \assert($localized instanceof \stdClass);
        $error = Patch::apply($localized, $patch->patches);
        if (null !== $error) {
            return new Result($this->card($json)->value, [new Issue('/localizations/'.Patch::escape($language), 'did not apply the localization: '.$error)]);
        }

        $localized->language = $language;

        return $this->card($localized);
    }

    /**
     * The PatchObject that turns a Card into its localized version, to set in
     * Card::$localizations. Localizations, language and version are left out.
     */
    public function localization(Card $card, Card $localized): PatchObject
    {
        return new PatchObject(Diff::between($this->json($card), $this->json($localized), ['localizations', 'language', 'version', '@type']));
    }

    /**
     * The JSON form of a Card, without its localizations.
     */
    private function json(Card $card): \stdClass
    {
        $json = new JsonEncoder(validate: false)->normalize($card);
        unset($json->localizations);

        return $json;
    }

    /**
     * @return Result<Card>
     */
    private function card(\stdClass $json): Result
    {
        return new JsonDecoder()->decode(json_encode($json, \JSON_THROW_ON_ERROR));
    }
}
