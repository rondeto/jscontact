<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Model\Name;
use Rondeto\JSContact\Model\NameComponent;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SortAsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SortAs) {
            throw new UnexpectedTypeException($constraint, SortAs::class);
        }

        if (!$value instanceof Name || [] === $value->sortAs) {
            return;
        }

        if ([] === $value->components) {
            $this->context->buildViolation('sortAs can only be set when components are')->atPath('sortAs')->addViolation();

            return;
        }

        $kinds = array_map(static fn (NameComponent $component): string => $component->kind, $value->components);
        foreach (array_keys($value->sortAs) as $kind) {
            if (!\in_array((string) $kind, $kinds, true)) {
                $this->context->buildViolation('no name component has this kind')->atPath('sortAs['.$kind.']')->addViolation();
            }
        }
    }
}
