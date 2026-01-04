<?php declare(strict_types=1);

namespace Frosh\MailValidation\Constraint;

use Frosh\MailValidation\Service\Validator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

#[AutoconfigureTag('monolog.logger', ['channel' => 'frosh-mail-validation'])]
#[AutoconfigureTag(name: 'validator.constraint_validator')]
class TestEmailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly Validator $emailValidator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!($constraint instanceof TestEmail)) {
            return;
        }

        if ($value === '' || !\is_string($value)) {
            return;
        }

        if ($this->emailValidator->validateEmail($value)) {
            return;
        }

        $this->context->buildViolation($constraint->getMessage())
            ->setParameter('{{ email }}', $this->formatValue($value))
            ->setCode(TestEmail::CODE)
            ->addViolation();
    }
}
