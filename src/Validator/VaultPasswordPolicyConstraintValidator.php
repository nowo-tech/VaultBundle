<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Validator;

use SensitiveParameter;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function implode;
use function is_string;
use function mb_strlen;
use function preg_match;

/**
 * Validates {@see VaultPasswordPolicyConstraint}: length and character classes (lowercase, uppercase, digit, symbol).
 */
final class VaultPasswordPolicyConstraintValidator extends ConstraintValidator
{
    /**
     * Adds one violation listing the missing rule codes (`min_length`, `min_classes`).
     */
    public function validate(#[SensitiveParameter] mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof VaultPasswordPolicyConstraint) {
            throw new UnexpectedTypeException($constraint, VaultPasswordPolicyConstraint::class);
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        $missing = [];
        if (mb_strlen($value) < $constraint->policy->minLength) {
            $missing[] = 'min_length';
        }
        $classes = 0;
        foreach (['/\p{Ll}/u', '/\p{Lu}/u', '/\d/', '/[^\p{L}\d]/u'] as $pattern) {
            $classes += preg_match($pattern, $value) === 1 ? 1 : 0;
        }
        if ($classes < $constraint->policy->minClasses) {
            $missing[] = 'min_classes';
        }

        if ($missing !== []) {
            $this->context->buildViolation($constraint->message)
                ->setTranslationDomain('NowoVaultBundle')
                ->setParameter('%rules%', implode(', ', $missing))
                ->setParameter('%min_length%', (string) $constraint->policy->minLength)
                ->setParameter('%min_classes%', (string) $constraint->policy->minClasses)
                ->setInvalidValue('')
                ->addViolation();
        }
    }
}
