<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<VaultItemFormData>
 */
final class VaultItemFormType extends AbstractType
{
    /**
     * @param array{level?: string, generator_mode?: string, use_password_toggle?: bool} $passwordFieldConfig
     */
    public function __construct(
        private readonly array $passwordFieldConfig = [],
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label'       => 'vault.form.title',
            ])
            ->add('username', TextType::class, ['required' => false, 'label' => 'vault.form.username'])
            ->add('websites', CollectionType::class, [
                'entry_type'     => TextType::class,
                'entry_options'  => ['label' => false],
                'allow_add'      => true,
                'allow_delete'   => true,
                'required'       => false,
                'label'          => 'vault.form.websites',
                'prototype_name' => '__website__',
            ])
            ->add('secureNote', TextareaType::class, ['required' => false, 'label' => 'vault.form.secure_note'])
            ->add('cardholderName', TextType::class, ['required' => false, 'label' => 'vault.form.cardholder'])
            ->add('cardNumber', TextType::class, ['required' => false, 'label' => 'vault.form.card_number'])
            ->add('expiry', TextType::class, ['required' => false, 'label' => 'vault.form.expiry'])
            ->add('cvv', PasswordType::class, ['required' => false, 'label' => 'vault.form.cvv', 'always_empty' => false])
            ->add('cardPin', PasswordType::class, ['required' => false, 'label' => 'vault.form.card_pin', 'always_empty' => false])
            ->add('fullName', TextType::class, ['required' => false, 'label' => 'vault.form.full_name'])
            ->add('email', TextType::class, ['required' => false, 'label' => 'vault.form.email'])
            ->add('phone', TextType::class, ['required' => false, 'label' => 'vault.form.phone'])
            ->add('addressLine1', TextType::class, ['required' => false, 'label' => 'vault.form.address1'])
            ->add('addressLine2', TextType::class, ['required' => false, 'label' => 'vault.form.address2'])
            ->add('city', TextType::class, ['required' => false, 'label' => 'vault.form.city'])
            ->add('state', TextType::class, ['required' => false, 'label' => 'vault.form.state'])
            ->add('postalCode', TextType::class, ['required' => false, 'label' => 'vault.form.postal_code'])
            ->add('country', TextType::class, ['required' => false, 'label' => 'vault.form.country'])
            ->add('documentNumber', TextType::class, ['required' => false, 'label' => 'vault.form.document_number'])
            ->add('issuedBy', TextType::class, ['required' => false, 'label' => 'vault.form.issued_by'])
            ->add('issuedDate', TextType::class, ['required' => false, 'label' => 'vault.form.issued_date'])
            ->add('expiryDate', TextType::class, ['required' => false, 'label' => 'vault.form.expiry_date'])
            ->add('note', TextareaType::class, ['required' => false, 'label' => 'vault.form.note']);

        $this->addPasswordField($builder);

        $builder->add('folder', EntityType::class, [
            'class'        => VaultFolder::class,
            'choices'      => $options['folders'],
            'choice_label' => 'name',
            'required'     => false,
            'placeholder'  => 'vault.form.no_folder',
            'label'        => 'vault.form.folder',
        ]);
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    private function addPasswordField(FormBuilderInterface $builder): void
    {
        $level = (string) ($this->passwordFieldConfig['level'] ?? 'medium');

        if (PasswordStrengthIntegration::isAvailable()) {
            $passwordType   = PasswordStrengthIntegration::PASSWORD_STRENGTH_TYPE;
            $validatorClass = PasswordStrengthIntegration::PASSWORD_STRENGTH_VALIDATOR;

            // @phpstan-ignore argument.type (optional PasswordStrengthBundle form type resolved at runtime)
            $builder->add('password', $passwordType, [
                'required'            => false,
                'label'               => 'vault.form.password',
                'translation_domain'  => 'NowoVaultBundle',
                'always_empty'        => false,
                'ui_framework'        => 'bootstrap5',
                'level'               => $level,
                'generator_mode'      => $this->passwordFieldConfig['generator_mode'] ?? 'input',
                'use_password_toggle' => $this->passwordFieldConfig['use_password_toggle'] ?? true,
                'constraints'         => [
                    // @phpstan-ignore class.notFound (optional PasswordStrengthBundle validator)
                    new $validatorClass([
                        'policyMode' => 'level',
                        'level'      => $level,
                    ]),
                ],
            ]);

            return;
        }

        $builder->add('password', PasswordType::class, [
            'required'     => false,
            'label'        => 'vault.form.password',
            'always_empty' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => VaultItemFormData::class,
            'translation_domain' => 'NowoVaultBundle',
            'folders'            => [],
        ]);
        $resolver->setAllowedTypes('folders', 'array');
    }
}
