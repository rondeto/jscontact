<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class VCardParamsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof VCardParams) {
            throw new UnexpectedTypeException($constraint, VCardParams::class);
        }

        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $name => $parameter) {
            $name = (string) $name; // PHP turns numeric string keys into integers
            if (1 !== preg_match('/^[a-z0-9-]+$/', $name)) {
                $this->context->buildViolation('not a lowercase vCard parameter name')->atPath('['.$name.']')->addViolation();
            } elseif (!\is_string($parameter) && (!\is_array($parameter) || [] === $parameter || !array_is_list($parameter) || [] !== array_filter($parameter, static fn (mixed $item): bool => !\is_string($item)))) {
                $this->context->buildViolation('must be a string or a non-empty list of strings')->atPath('['.$name.']')->addViolation();
            }
        }
    }
}
