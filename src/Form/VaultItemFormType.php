<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Integration\TagInputIntegration;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vault item create/edit form (login, card, identity, note fields).
 *
 * @extends AbstractType<VaultItemFormData>
 */
#[FormKitConfig('vault')]
final class VaultItemFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $optional = static fn (array $extra = []): array => array_merge(['required' => false, 'empty_data' => ''], $extra);

        $this->withBuilder($builder, function () use ($optional, $options): void {
            $this->addTextField('title', [
                'constraints' => [new NotBlank()],
                'label'       => 'vault.form.title',
            ]);
            $this->addTextField('username', $optional(['label' => 'vault.form.username']));
            $this->addTypedField('websites', CollectionType::class, [
                'entry_type'     => TextType::class,
                'entry_options'  => $optional(['label' => false]),
                'allow_add'      => true,
                'allow_delete'   => true,
                'required'       => false,
                'empty_data'     => static fn (): array => [''],
                'label'          => 'vault.form.websites',
                'prototype_name' => '__website__',
            ]);
            $this->addTextareaField('secureNote', $optional([
                'label' => 'vault.form.secure_note',
                'attr'  => ['rows' => 6],
            ]));
            $this->addTextField('cardholderName', $optional(['label' => 'vault.form.cardholder']));
            $this->addTextField('cardNumber', $optional(['label' => 'vault.form.card_number']));
            $this->addTextField('expiry', $optional(['label' => 'vault.form.expiry']));
            $this->addPasswordField('cvv', $optional([
                'label'        => 'vault.form.cvv',
                'always_empty' => false,
            ]));
            $this->addPasswordField('cardPin', $optional([
                'label'        => 'vault.form.card_pin',
                'always_empty' => false,
            ]));
            $this->addTextField('fullName', $optional(['label' => 'vault.form.full_name']));
            $this->addTextField('email', $optional(['label' => 'vault.form.email']));
            $this->addTextField('phone', $optional(['label' => 'vault.form.phone']));
            $this->addTextField('addressLine1', $optional(['label' => 'vault.form.address1']));
            $this->addTextField('addressLine2', $optional(['label' => 'vault.form.address2']));
            $this->addTextField('city', $optional(['label' => 'vault.form.city']));
            $this->addTextField('state', $optional(['label' => 'vault.form.state']));
            $this->addTextField('postalCode', $optional(['label' => 'vault.form.postal_code']));
            $this->addTextField('country', $optional(['label' => 'vault.form.country']));
            $this->addTextField('documentNumber', $optional(['label' => 'vault.form.document_number']));
            $this->addTextField('issuedBy', $optional(['label' => 'vault.form.issued_by']));
            $this->addTextField('issuedDate', $optional(['label' => 'vault.form.issued_date']));
            $this->addTextField('expiryDate', $optional(['label' => 'vault.form.expiry_date']));
            $this->addTextareaField('note', $optional([
                'label' => 'vault.form.note',
                'attr'  => ['rows' => 3],
            ]));

            $this->addTagsField($optional);
            $this->addItemPasswordField();

            $this->addTypedField('folder', EntityType::class, [
                'class'        => VaultFolder::class,
                'choices'      => $options['folders'],
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'vault.form.no_folder',
                'label'        => 'vault.form.folder',
            ]);
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $optional
     */
    private function addTagsField(callable $optional): void
    {
        if (TagInputIntegration::isAvailable()) {
            $tagType = TagInputIntegration::TAG_INPUT_TYPE;

            // @phpstan-ignore argument.type (optional TagInputBundle form type resolved at runtime)
            $this->addTypedField('tags', $tagType, [
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

        $this->addTypedField('tags', CollectionType::class, [
            'entry_type'     => TextType::class,
            'entry_options'  => $optional(['label' => false]),
            'allow_add'      => true,
            'allow_delete'   => true,
            'required'       => false,
            'empty_data'     => static fn (): array => [''],
            'label'          => 'vault.form.tags',
            'prototype_name' => '__tag__',
        ]);

        $this->boundBuilder()->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
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

    private function addItemPasswordField(): void
    {
        $passwordFieldConfig = $this->runtimeConfig->get()['password_field'];
        $level               = (string) ($passwordFieldConfig['level'] ?? 'medium');

        if (PasswordStrengthIntegration::isAvailable()) {
            $passwordType   = PasswordStrengthIntegration::PASSWORD_STRENGTH_TYPE;
            $validatorClass = PasswordStrengthIntegration::PASSWORD_STRENGTH_VALIDATOR;

            // @phpstan-ignore argument.type (optional PasswordStrengthBundle form type resolved at runtime)
            $this->addTypedField('password', $passwordType, [
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

        $this->addPasswordField('password', [
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
