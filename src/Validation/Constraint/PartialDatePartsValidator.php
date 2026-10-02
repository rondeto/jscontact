<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Model\PartialDate;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PartialDatePartsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PartialDateParts) {
            throw new UnexpectedTypeException($constraint, PartialDateParts::class);
        }

        if (!$value instanceof PartialDate) {
            return;
        }

        if (null === $value->year && null === $value->month) {
            $this->context->buildViolation('a partial date needs a year, a month, or both')->addViolation();
        } elseif (null !== $value->month && null === $value->year && null === $value->day) {
            $this->context->buildViolation('a month needs a year or a day')->atPath('month')->addViolation();
        } elseif (null !== $value->day && null === $value->month) {
            $this->context->buildViolation('a day needs a month')->atPath('day')->addViolation();
        } elseif (null !== $value->day && $value->month >= 1 && $value->month <= 12
            // Without a year, February 29 is allowed.
            && $value->day >= 1 && !checkdate($value->month, $value->day, $value->year ?? 2000)) {
            $this->context->buildViolation('this month has no such day')->atPath('day')->addViolation();
        }
    }
}
