<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Json\JsonDecoder;
use Rondeto\JSContact\Json\JsonEncoder;
use Rondeto\JSContact\Localization\Internal\Patch;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class LocalizationsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Localizations) {
            throw new UnexpectedTypeException($constraint, Localizations::class);
        }

        if (!$value instanceof Card || [] === $value->localizations) {
            return;
        }

        $json = new JsonEncoder(validate: false)->normalize($value);
        unset($json->localizations);
        $encoded = json_encode($json, \JSON_THROW_ON_ERROR);
        // Problems of the Card itself are reported for the Card, not for its localizations.
        $known = array_map(strval(...), new JsonDecoder()->decode($encoded)->issues);

        foreach ($value->localizations as $language => $localization) {
            $language = (string) $language;
            $path = 'localizations['.$language.']';
            if (1 !== preg_match(Syntax::LANGUAGE_TAG, $language)) {
                $this->context->buildViolation('not a language tag')->atPath($path)->addViolation();
            }

            foreach (array_keys($localization->patches) as $patchPath) {
                if ('localizations' === (Patch::tokens((string) $patchPath)[0] ?? null)) {
                    $this->context->buildViolation('a localization cannot patch localizations')->atPath($path.'['.$patchPath.']')->addViolation();
                    continue 2;
                }
            }

            $localized = json_decode($encoded, false, 512, \JSON_THROW_ON_ERROR);
            \assert($localized instanceof \stdClass);
            $error = Patch::apply($localized, $localization->patches);
            if (null !== $error) {
                $this->context->buildViolation($error)->atPath($path)->addViolation();
                continue;
            }

            // A patch is valid if the Card it gives is (RFC 9553, section 1.4.3).
            foreach (new JsonDecoder()->decode(json_encode($localized, \JSON_THROW_ON_ERROR))->issues as $issue) {
                if (!\in_array((string) $issue, $known, true)) {
                    $this->context->buildViolation(\sprintf('gives an invalid Card: %s', $issue))->atPath($path.'['.$this->patchOf($issue->path, array_keys($localization->patches)).']')->addViolation();
                }
            }
        }
    }

    /**
     * The patch that changed the value at a JSON Pointer.
     *
     * @param list<array-key> $patchPaths
     */
    private function patchOf(string $pointer, array $patchPaths): string
    {
        foreach ($patchPaths as $patchPath) {
            $patchPath = (string) $patchPath;
            if ('/'.$patchPath === $pointer || str_starts_with($pointer, '/'.$patchPath.'/')) {
                return $patchPath;
            }
        }

        return '';
    }
}
