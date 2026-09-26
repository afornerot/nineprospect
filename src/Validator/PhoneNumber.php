<?php

namespace App\Validator;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Vérifie qu'une chaîne est un numéro de téléphone valide selon libphonenumber,
 * en utilisant la région par défaut fournie (FR par défaut).
 *
 * Chaîne vide ou null : valide (utiliser {@see \Symfony\Component\Validator\Constraints\NotBlank}
 * en complément si le champ est obligatoire).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class PhoneNumber extends Constraint
{
    public string $message = 'Numéro de téléphone invalide.';

    public function __construct(
        public string $defaultRegion = 'FR',
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
        if (null !== $message) {
            $this->message = $message;
        }
    }
}

class PhoneNumberValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PhoneNumber) {
            throw new UnexpectedTypeException($constraint, PhoneNumber::class);
        }

        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        try {
            PhoneNumberUtil::getInstance()->parse((string) $value, $constraint->defaultRegion);
        } catch (NumberParseException) {
            $this->context
                ->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
