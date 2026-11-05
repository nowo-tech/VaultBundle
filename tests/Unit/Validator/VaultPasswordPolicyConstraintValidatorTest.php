<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Validator;

use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Validator\VaultPasswordPolicyConstraint;
use Nowo\VaultBundle\Validator\VaultPasswordPolicyConstraintValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Enforce-on-save password policy (1.16.0 §7.5): length and character classes; an empty value keeps the stored
 * password; the violation names the missing rules, never the value.
 *
 * @extends ConstraintValidatorTestCase<VaultPasswordPolicyConstraintValidator>
 */
final class VaultPasswordPolicyConstraintValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new VaultPasswordPolicyConstraintValidator();
    }

    public function testCompliantEmptyAndNonStringValuesPass(): void
    {
        $constraint = new VaultPasswordPolicyConstraint(new VaultPasswordPolicy(12, 3));
        $this->validator->validate('Abcdefgh1234', $constraint);
        $this->validator->validate('', $constraint);
        $this->validator->validate(null, $constraint);

        $this->assertNoViolation();
    }

    public function testViolationListsTheMissingRulesOnly(): void
    {
        $marker     = 'MARKshort';
        $constraint = new VaultPasswordPolicyConstraint(new VaultPasswordPolicy(16, 4));
        $this->validator->validate($marker, $constraint);

        $this->buildViolation('vault.form.password_policy')
            ->setParameter('%rules%', 'min_length, min_classes')
            ->setParameter('%min_length%', '16')
            ->setParameter('%min_classes%', '4')
            ->setInvalidValue('')
            ->assertRaised();
    }

    public function testOnlyTheClassRule(): void
    {
        $this->validator->validate('abcdefghijklmnop', new VaultPasswordPolicyConstraint(new VaultPasswordPolicy(12, 2)));

        $this->buildViolation('vault.form.password_policy')
            ->setParameter('%rules%', 'min_classes')
            ->setParameter('%min_length%', '12')
            ->setParameter('%min_classes%', '2')
            ->setInvalidValue('')
            ->assertRaised();
    }

    public function testDefaultPolicyAndWrongConstraint(): void
    {
        self::assertSame(12, (new VaultPasswordPolicyConstraint())->policy->minLength);

        $this->expectException(UnexpectedTypeException::class);
        $this->validator->validate('x', new NotBlank());
    }
}
