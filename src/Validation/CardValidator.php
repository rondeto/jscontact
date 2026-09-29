<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation;

use Rondeto\JSContact\Conversion\Issue;
use Rondeto\JSContact\Model\Card;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Checks a Card against the rules of RFC 9553 (as updated by RFC 9982) that its PHP types
 * cannot express.
 *
 * The rules are Symfony Validator constraints on the model classes, so any Symfony
 * validator can check a Card too. This class reports them as Issues whose paths are JSON
 * Pointers into the Card's JSON form, like the issues found while reading JSON.
 */
final readonly class CardValidator
{
    private ValidatorInterface $validator;

    public function __construct(?ValidatorInterface $validator = null)
    {
        $this->validator = $validator ?? Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return list<Issue>
     */
    public function validate(Card $card): array
    {
        $issues = [];
        foreach ($this->validator->validate($card) as $violation) {
            $issues[] = new Issue($this->jsonPointer($violation->getPropertyPath()), (string) $violation->getMessage());
        }

        return $issues;
    }

    /**
     * Converts a Symfony property path, such as "emails[e1].extra[example.com:x]", to the
     * JSON Pointer of the same value, "/emails/e1/example.com:x": the properties in $extra
     * are written in their parent object.
     */
    private function jsonPointer(string $propertyPath): string
    {
        // Each match is either "[key]" (group 1) or a property name (group 2).
        preg_match_all('/\[([^\]]*)\]|([^.\[\]]+)/', $propertyPath, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $pointer = '';
        foreach ($matches as [, $key, $property]) {
            if ('extra' === $property) {
                continue;
            }

            $token = $property ?? $key ?? '';

            $pointer .= '/'.strtr($token, ['~' => '~0', '/' => '~1']);
        }

        return $pointer;
    }
}
