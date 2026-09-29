<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class IdKeysValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof IdKeys) {
            throw new UnexpectedTypeException($constraint, IdKeys::class);
        }

        if (!\is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $id) {
            $id = (string) $id; // PHP turns numeric string keys into integers
            if (!Syntax::isId($id)) {
                $this->context->buildViolation('not a valid Id')->atPath('['.$id.']')->addViolation();
            }
        }
    }
}
