<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Model\Address;
use Rondeto\JSContact\Model\Name;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ComponentsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Components) {
            throw new UnexpectedTypeException($constraint, Components::class);
        }

        if (!$value instanceof Name && !$value instanceof Address) {
            return;
        }

        $hasValue = false;
        $previousIsSeparator = false;
        foreach ($value->components as $index => $component) {
            $isSeparator = 'separator' === $component->kind;
            if ($isSeparator && !$value->isOrdered) {
                $this->add('components['.$index.']', 'separators are only allowed when isOrdered is true');
            }

            if ($isSeparator && $previousIsSeparator) {
                $this->add('components['.$index.']', 'two separators cannot follow each other');
            }

            $hasValue = $hasValue || !$isSeparator;
            $previousIsSeparator = $isSeparator;
        }

        if ([] !== $value->components && !$hasValue) {
            $this->add('components', 'at least one component must not be a separator');
        }

        if (null !== $value->defaultSeparator && (!$value->isOrdered || [] === $value->components)) {
            $this->add('defaultSeparator', 'defaultSeparator requires ordered components');
        }

        if (null === $value->phoneticSystem && null === $value->phoneticScript) {
            foreach ($value->components as $index => $component) {
                if (null !== $component->phonetic) {
                    $this->add('components['.$index.'].phonetic', 'phonetic requires phoneticSystem or phoneticScript');
                }
            }
        }
    }

    private function add(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
