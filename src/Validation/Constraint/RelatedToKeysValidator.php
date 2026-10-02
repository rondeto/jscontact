<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class RelatedToKeysValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RelatedToKeys) {
            throw new UnexpectedTypeException($constraint, RelatedToKeys::class);
        }

        if (\is_array($value) && \array_key_exists('', $value)) {
            $this->context->buildViolation('a related Card needs a uid')->atPath('[]')->addViolation();
        }
    }
}
