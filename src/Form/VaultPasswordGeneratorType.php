<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Password generator modal controls (REQ-TWIG-005).
 *
 * Browser-only: vault.js reads them through {@code data-vault-password-*} attributes and the
 * {@code vault-gen-mode} radio name; the form is never submitted. Children get an empty name (the mode radios keep
 * {@code vault-gen-mode}) so nothing is ever posted.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultPasswordGeneratorType extends AbstractType
{
    use FormOptionsTrait;

    public const MODE_NAME = 'vault-gen-mode';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('output', TextType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => false,
            'attr'     => ['class' => 'form-control', 'readonly' => true, 'data-vault-password-output' => true],
        ]);
        $builder->add('length', RangeType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'vault.generator.length',
            'data'       => 20,
            'label_attr' => ['class' => 'form-label'],
            'attr'       => ['class' => 'form-range', 'min' => 8, 'max' => 64, 'data-vault-password-length' => true],
        ]);
        $builder->add('mode', ChoiceType::class, [
            'mapped'   => false,
            'required' => true,
            'expanded' => true,
            'label'    => false,
            'data'     => 'characters',
            'choices'  => [
                'vault.generator.mode_characters' => 'characters',
                'vault.generator.mode_words'      => 'words',
            ],
            'choice_attr' => static fn (): array => ['class' => 'form-check-input'],
        ]);

        foreach (['upper' => 'A-Z', 'digits' => '0-9', 'symbols' => '!@#'] as $name => $label) {
            $builder->add($name, CheckboxType::class, [
                'mapped'             => false,
                'required'           => false,
                'data'               => true,
                'label'              => $label,
                'translation_domain' => false,
                'attr'               => ['class' => 'form-check-input', 'data-vault-password-' . $name => true],
            ]);
        }
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        foreach ($view->children as $name => $child) {
            $child->vars['id']        = 'vault-gen-' . $name;
            $child->vars['full_name'] = $name === 'mode' ? self::MODE_NAME : '';
        }

        foreach ($view->children['mode']->children as $value => $radio) {
            $radio->vars['full_name'] = self::MODE_NAME;
            $radio->vars['id']        = 'vault-gen-mode-' . $value;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'NowoVaultBundle',
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'vault_password_generator';
    }
}
