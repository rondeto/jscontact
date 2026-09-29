<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Model\Card;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class GroupMembersValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof GroupMembers) {
            throw new UnexpectedTypeException($constraint, GroupMembers::class);
        }

        if ($value instanceof Card && [] !== $value->members && Card::KIND_GROUP !== $value->kind) {
            $this->context->buildViolation('members can only be set when kind is "group"')->atPath('members')->addViolation();
        }
    }
}
