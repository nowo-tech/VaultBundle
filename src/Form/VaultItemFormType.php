<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Integration\TagInputIntegration;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<VaultItemFormData>
 */
final class VaultItemFormType extends AbstractType
{
    public function __construct(
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $optional = static fn (array $extra = []): array => array_merge(['required' => false, 'empty_data' => ''], $extra);

        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label'       => 'vault.form.title',
            ])
            ->add('username', TextType::class, $optional(['label' => 'vault.form.username']))
            ->add('websites', CollectionType::class, [
                'entry_type'     => TextType::class,
                'entry_options'  => $optional(['label' => false]),
                'allow_add'      => true,
                'allow_delete'   => true,
                'required'       => false,
                'empty_data'     => static fn (): array => [''],
                'label'          => 'vault.form.websites',
                'prototype_name' => '__website__',
            ])
            ->add('secureNote', TextareaType::class, $optional(['label' => 'vault.form.secure_note', 'attr' => ['rows' => 6]]))
            ->add('cardholderName', TextType::class, $optional(['label' => 'vault.form.cardholder']))
            ->add('cardNumber', TextType::class, $optional(['label' => 'vault.form.card_number']))
            ->add('expiry', TextType::class, $optional(['label' => 'vault.form.expiry']))
            ->add('cvv', PasswordType::class, $optional(['label' => 'vault.form.cvv', 'always_empty' => false]))
            ->add('cardPin', PasswordType::class, $optional(['label' => 'vault.form.card_pin', 'always_empty' => false]))
            ->add('fullName', TextType::class, $optional(['label' => 'vault.form.full_name']))
            ->add('email', TextType::class, $optional(['label' => 'vault.form.email']))
            ->add('phone', TextType::class, $optional(['label' => 'vault.form.phone']))
            ->add('addressLine1', TextType::class, $optional(['label' => 'vault.form.address1']))
            ->add('addressLine2', TextType::class, $optional(['label' => 'vault.form.address2']))
            ->add('city', TextType::class, $optional(['label' => 'vault.form.city']))
            ->add('state', TextType::class, $optional(['label' => 'vault.form.state']))
            ->add('postalCode', TextType::class, $optional(['label' => 'vault.form.postal_code']))
            ->add('country', TextType::class, $optional(['label' => 'vault.form.country']))
            ->add('documentNumber', TextType::class, $optional(['label' => 'vault.form.document_number']))
            ->add('issuedBy', TextType::class, $optional(['label' => 'vault.form.issued_by']))
            ->add('issuedDate', TextType::class, $optional(['label' => 'vault.form.issued_date']))
            ->add('expiryDate', TextType::class, $optional(['label' => 'vault.form.expiry_date']))
            ->add('note', TextareaType::class, $optional(['label' => 'vault.form.note', 'attr' => ['rows' => 3]]));

        $this->addTagsField($builder, $optional);

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
     * @param callable(array<string, mixed>): array<string, mixed> $optional
     */
    private function addTagsField(FormBuilderInterface $builder, callable $optional): void
    {
        if (TagInputIntegration::isAvailable()) {
            $tagType = TagInputIntegration::TAG_INPUT_TYPE;

            // @phpstan-ignore argument.type (optional TagInputBundle form type resolved at runtime)
            $builder->add('tags', $tagType, [
                'required'           => false,
                'label'              => 'vault.form.tags',
                'translation_domain' => 'NowoVaultBundle',
                'input_class'        => 'form-control',
                'placeholder'        => $this->translator->trans('vault.form.tag_placeholder', [], 'NowoVaultBundle'),
                'duplicates'         => false,
                'dropdown_enabled'   => true,
            ]);

            return;
        }

        $builder->add('tags', CollectionType::class, [
            'entry_type'     => TextType::class,
            'entry_options'  => $optional(['label' => false]),
            'allow_add'      => true,
            'allow_delete'   => true,
            'required'       => false,
            'empty_data'     => static fn (): array => [''],
            'label'          => 'vault.form.tags',
            'prototype_name' => '__tag__',
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!$data instanceof VaultItemFormData) {
                return;
            }

            $data->tags = array_values(array_filter(
                $data->tags,
                static fn (string $tag): bool => trim($tag) !== '',
            ));
        });
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    private function addPasswordField(FormBuilderInterface $builder): void
    {
        $passwordFieldConfig = $this->runtimeConfig->get()['password_field'];
        $level               = (string) ($passwordFieldConfig['level'] ?? 'medium');

        if (PasswordStrengthIntegration::isAvailable()) {
            $passwordType   = PasswordStrengthIntegration::PASSWORD_STRENGTH_TYPE;
            $validatorClass = PasswordStrengthIntegration::PASSWORD_STRENGTH_VALIDATOR;

            // @phpstan-ignore argument.type (optional PasswordStrengthBundle form type resolved at runtime)
            $builder->add('password', $passwordType, [
                'required'            => false,
                'empty_data'          => '',
                'label'               => 'vault.form.password',
                'translation_domain'  => 'NowoVaultBundle',
                'always_empty'        => false,
                'ui_framework'        => 'bootstrap5',
                'level'               => $level,
                'generator_mode'      => $passwordFieldConfig['generator_mode'] ?? 'input',
                'use_password_toggle' => $passwordFieldConfig['use_password_toggle'] ?? true,
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
            'empty_data'   => '',
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
