<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Model\Card;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TitleOrganizationsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TitleOrganizations) {
            throw new UnexpectedTypeException($constraint, TitleOrganizations::class);
        }

        if (!$value instanceof Card) {
            return;
        }

        foreach ($value->titles as $key => $title) {
            if (null !== $title->organizationId && !isset($value->organizations[$title->organizationId])) {
                $this->context->buildViolation('no organization has this Id')->atPath('titles['.$key.'].organizationId')->addViolation();
            }
        }
    }
}
