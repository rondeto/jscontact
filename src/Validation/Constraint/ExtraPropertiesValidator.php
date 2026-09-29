<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Validation\Constraint;

use Rondeto\JSContact\Validation\Syntax;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ExtraPropertiesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExtraProperties) {
            throw new UnexpectedTypeException($constraint, ExtraProperties::class);
        }

        if (!\is_object($value) || !property_exists($value, 'extra') || !\is_array($value->extra)) {
            return;
        }

        // The JSON properties the object models are its public PHP properties.
        $modeled = ['@type'];
        foreach (new \ReflectionObject($value)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ('extra' !== $property->getName()) {
                $modeled[] = strtolower($property->getName());
            }
        }

        foreach (array_keys($value->extra) as $name) {
            $name = (string) $name; // PHP turns numeric string keys into integers
            if (\in_array(strtolower($name), $modeled, true)) {
                $message = 'this property is modeled: set it on the object, not in extra';
            } elseif ('extra' === strtolower($name)) {
                $message = '"extra" is a reserved property name';
            } elseif (null !== ($expected = Syntax::caseVariantOf($name, $constraint->registered))) {
                $message = \sprintf('must be written "%s"', $expected);
            } elseif (!Syntax::isIanaName($name) && !Syntax::isVendorExtension($name)) {
                $message = 'not a valid property name';
            } else {
                continue;
            }

            $this->context->buildViolation($message)->atPath('extra['.$name.']')->addViolation();
        }
    }
}
