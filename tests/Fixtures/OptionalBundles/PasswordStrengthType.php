<?php

declare(strict_types=1);

namespace Nowo\PasswordStrengthBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Minimal stand-in for nowo-tech/password-strength-bundle's PasswordStrengthType (not installed in this repo),
 * loaded only by tests that run in a separate process.
 */
final class PasswordStrengthType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined(['ui_framework', 'level', 'generator_mode', 'use_password_toggle', 'ui_translation_domain']);
    }

    public function getParent(): string
    {
        return PasswordType::class;
    }
}
