<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard;

use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Validation\CardValidator;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\Internal\Exporter;
use Sabre\VObject\Component\VCard;

/**
 * Converts Cards to vCards, by the reverse rules of RFC 9555 (section 3).
 *
 * Every entry of an Id map gets its key as PROP-ID, and every label an X-ABLabel, so that
 * converting the vCard back gives the same Card. What vCard cannot hold, or the requested
 * vCard version cannot, is reported as an issue.
 *
 * Writing is strict by default: a Card that breaks the JSContact specification is refused.
 * Pass validate: false to write it anyway.
 */
final readonly class VCardEncoder
{
    public function __construct(
        private bool $validate = true,
        private CardValidator $validator = new CardValidator(),
    ) {
    }

    /**
     * @return Result<string>
     *
     * @throws InvalidCardException when validation is on and the Card is invalid
     */
    public function encode(Card $card, VCardVersion $version = VCardVersion::V40): Result
    {
        $result = $this->convert($card, $version);

        return new Result($result->value->serialize(), $result->issues);
    }

    /**
     * @return Result<VCard>
     *
     * @throws InvalidCardException when validation is on and the Card is invalid
     */
    public function convert(Card $card, VCardVersion $version = VCardVersion::V40): Result
    {
        $issues = $this->validate ? $this->validator->validate($card) : [];
        if ([] !== $issues) {
            throw new InvalidCardException($issues);
        }

        return new Exporter($version)->export($card);
    }
}
