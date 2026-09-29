<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AtLeastOnePropertyValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AtLeastOneProperty) {
            throw new UnexpectedTypeException($constraint, AtLeastOneProperty::class);
        }

        if (!\is_object($value)) {
            return;
        }

        $properties = get_object_vars($value);
        foreach ($constraint->properties as $property) {
            if (null !== ($properties[$property] ?? null) && [] !== $properties[$property]) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)->addViolation();
    }
}
