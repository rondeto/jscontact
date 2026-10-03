<?php

declare(strict_types=1);

namespace Rondeto\JSContact\VCard;

use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Conversion\Result;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Validation\CardValidator;
use Rondeto\JSContact\Validation\InvalidCardException;
use Rondeto\JSContact\VCard\Dialect\Dialect;
use Rondeto\JSContact\VCard\Internal\Importer;
use Rondeto\JSContact\VCard\Internal\Parser;
use Sabre\VObject\Component\VCard;

/**
 * Converts vCards (versions 2.1, 3.0 and 4.0) to Cards, as RFC 9555 specifies (updated by
 * RFC 9982).
 *
 * Reading is lenient by default: a property that cannot be converted is kept verbatim in
 * Card::$vCardProps, and reported as an issue, as are the values that break the JSContact
 * specification. Keys of Id maps come from PROP-ID when set, and are named after the
 * vCard property otherwise ("EMAIL-1", "PHONE-2"…), so converting the same vCard twice
 * gives the same keys.
 *
 * With strict: true, any issue makes the conversion fail instead.
 *
 * Dialects first rewrite the vendor properties they know as the RFC properties they mean,
 * in the order given. As the address book a vCard comes from is seldom known, pass
 * Dialects::all() to read them all.
 */
final readonly class VCardDecoder
{
    /**
     * @param list<Dialect> $dialects
     */
    public function __construct(
        private bool $strict = false,
        private CardValidator $validator = new CardValidator(),
        private array $dialects = [],
    ) {
    }

    /**
     * Converts every vCard of a text, such as a .vcf file.
     *
     * @return list<Result<Card>> One result per vCard; a vCard that cannot be read at all
     *                            gives an empty Card with an issue
     *
     * @throws InvalidCardException in strict mode, when a vCard has any issue
     */
    public function decode(string $vCards): array
    {
        $results = [];
        foreach (Parser::parse($vCards) as $parsed) {
            if (null === $parsed->vCard) {
                $result = new Result(new Card(), array_map(static fn (string $issue): Issue => new Issue('', $issue), $parsed->issues));
            } else {
                $result = $this->import($parsed->vCard, $parsed->rawValues, $parsed->issues);
            }

            $results[] = $this->checked($result);
        }

        return $results;
    }

    /**
     * Converts a vCard already read by sabre/vobject.
     *
     * Prefer decode() when you have the vCard text: sabre/vobject cannot tell an escaped
     * comma in a name or address component from a list separator.
     *
     * @return Result<Card>
     *
     * @throws InvalidCardException in strict mode, when the vCard has any issue
     */
    public function convert(VCard $vCard): Result
    {
        // Dialects rewrite the vCard: leave the caller's one as it is.
        return $this->checked($this->import(clone $vCard, []));
    }

    /**
     * @param array<string, list<string>> $rawValues   See ParsedCard
     * @param list<string>                $parseIssues
     *
     * @return Result<Card>
     */
    private function import(VCard $vCard, array $rawValues, array $parseIssues = []): Result
    {
        $dialectIssues = [];
        foreach ($this->dialects as $dialect) {
            array_push($dialectIssues, ...$dialect->read($vCard));
        }

        $result = new Importer($rawValues, $this->validator)->import($vCard, $parseIssues);

        return [] === $dialectIssues ? $result : new Result($result->value, [...$dialectIssues, ...$result->issues]);
    }

    /**
     * @param Result<Card> $result
     *
     * @return Result<Card>
     */
    private function checked(Result $result): Result
    {
        if ($this->strict && [] !== $result->issues) {
            throw new InvalidCardException($result->issues);
        }

        return $result;
    }
}
