<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class RegisteredValueValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RegisteredValue) {
            throw new UnexpectedTypeException($constraint, RegisteredValue::class);
        }

        if (\is_string($value)) {
            $this->check($value, $constraint->values, null);
        } elseif (\is_array($value)) {
            // A set: each value is a key of the JSON object, hence its path.
            foreach ($value as $item) {
                if (\is_string($item)) {
                    $this->check($item, $constraint->values, '['.$item.']');
                }
            }
        }
    }

    /**
     * @param list<string> $known
     */
    private function check(string $value, array $known, ?string $path): void
    {
        if ('' === $value) {
            $message = 'must not be empty';
        } elseif (null !== ($expected = Syntax::caseVariantOf($value, $known))) {
            $message = \sprintf('"%s" must be written "%s"', $value, $expected);
        } else {
            return;
        }

        $violation = $this->context->buildViolation($message);
        if (null !== $path) {
            $violation->atPath($path);
        }

        $violation->addViolation();
    }
}
