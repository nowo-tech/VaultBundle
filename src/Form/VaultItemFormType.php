<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Integration\TagInputIntegration;
use Nowo\VaultBundle\Validator\VaultPasswordPolicyConstraint;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_combine;
use function array_merge;
use function array_merge_recursive;
use function in_array;

/**
 * Vault item create/edit form (login, card, identity, note fields).
 *
 * @extends AbstractType<VaultItemFormData>
 */
#[FormKitConfig('vault')]
final class VaultItemFormType extends AbstractType
{
    use FormOptionsTrait;

    /** Labels of the "clear stored values" choices, per secret payload key. */
    private const SECRET_LABELS = [
        'password'       => 'vault.form.password',
        'note'           => 'vault.form.note',
        'content'        => 'vault.form.secure_note',
        'cardNumber'     => 'vault.form.card_number',
        'cvv'            => 'vault.form.cvv',
        'cardPin'        => 'vault.form.card_pin',
        'documentNumber' => 'vault.form.document_number',
    ];

    /**
     * Creates the form type with the runtime configuration provider and the translator.
     */
    public function __construct(
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Adds the common fields and the type-specific fields for every vault item type.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $optional = static fn (array $extra = []): array => array_merge(['required' => false, 'empty_data' => ''], $extra);
        /** @var list<string> $storedSecrets */
        $storedSecrets = $options['stored_secret_fields'];
        // Secret fields are never filled from the stored payload (1.15.2): a stored value only shows as a placeholder and a
        // data attribute the reveal script binds to; an empty submit keeps it (VaultItemFormData::keepStoredSecrets()).
        $secret = static fn (string $payloadKey, array $extra = []): array => in_array($payloadKey, $storedSecrets, true)
            ? array_merge_recursive($extra, ['attr' => ['placeholder' => 'vault.form.secret_stored_placeholder', 'data-vault-secret-field' => $payloadKey]])
            : $extra;

        $this->withBuilder($builder, function () use ($optional, $options, $secret): void {
            $this->addTextField('title', array_merge([
                'constraints' => [new NotBlank()],
                'label'       => 'vault.form.title',
                // An empty title reaches NotBlank (422) instead of a null on the string property (500).
                'empty_data' => '',
            ], $options['scope_item'] ? ['help' => 'vault.form.title_scope_help'] : []));
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
            $this->addTextareaField('secureNote', $optional($secret('content', [
                'label' => 'vault.form.secure_note',
                'attr'  => ['rows' => 6],
            ])));
            $this->addTextField('cardholderName', $optional(['label' => 'vault.form.cardholder']));
            $this->addTextField('cardNumber', $optional($secret('cardNumber', ['label' => 'vault.form.card_number', 'attr' => ['autocomplete' => 'off']])));
            $this->addTextField('expiry', $optional(['label' => 'vault.form.expiry']));
            $this->addPasswordField('cvv', $optional($secret('cvv', [
                'label'        => 'vault.form.cvv',
                'always_empty' => true,
            ])));
            $this->addPasswordField('cardPin', $optional($secret('cardPin', [ // ggignore: form field name, not a credential
                'label'        => 'vault.form.card_pin',
                'always_empty' => true,
            ])));
            $this->addTextField('fullName', $optional(['label' => 'vault.form.full_name']));
            $this->addTextField('email', $optional(['label' => 'vault.form.email']));
            $this->addTextField('phone', $optional(['label' => 'vault.form.phone']));
            $this->addTextField('addressLine1', $optional(['label' => 'vault.form.address1']));
            $this->addTextField('addressLine2', $optional(['label' => 'vault.form.address2']));
            $this->addTextField('city', $optional(['label' => 'vault.form.city']));
            $this->addTextField('state', $optional(['label' => 'vault.form.state']));
            $this->addTextField('postalCode', $optional(['label' => 'vault.form.postal_code']));
            $this->addTextField('country', $optional(['label' => 'vault.form.country']));
            $this->addTextField('documentNumber', $optional($secret('documentNumber', ['label' => 'vault.form.document_number', 'attr' => ['autocomplete' => 'off']])));
            $this->addTextField('issuedBy', $optional(['label' => 'vault.form.issued_by']));
            $this->addTextField('issuedDate', $optional(['label' => 'vault.form.issued_date']));
            $this->addTextField('expiryDate', $optional(['label' => 'vault.form.expiry_date']));
            $this->addTextareaField('note', $optional($secret('note', [
                'label' => 'vault.form.note',
                'attr'  => ['rows' => 3],
            ])));

            $this->addTagsField($optional);
            // 1.16.0: the owner's password policy, when it is enforced on save (an empty value keeps the stored one).
            $policy = $options['password_policy'];
            $this->addItemPasswordField(array_merge_recursive($secret('password'), $policy instanceof VaultPasswordPolicy && $policy->enforceOnSave ? ['constraints' => [new VaultPasswordPolicyConstraint($policy)]] : []), (bool) $options['strength_meter']);

            $this->addTypedField('folder', EntityType::class, [
                'class'        => VaultFolder::class,
                'choices'      => $options['folders'],
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'vault.form.no_folder',
                'label'        => 'vault.form.folder',
            ]);
        });

        if ($options['scope_item']) {
            // 1.16.0 (Q-O4): a scope item can be kept out of the metadata catalogue; access is unchanged.
            $builder->add('catalogueHidden', CheckboxType::class, [
                'required' => false,
                'label'    => 'vault.form.catalogue_hidden',
                'help'     => 'vault.form.catalogue_hidden_help',
            ]);
        }

        if ($options['allow_secret_clear'] && $storedSecrets !== []) {
            // Unmapped: the stored secrets the user explicitly empties (an empty secret field otherwise keeps its value).
            $builder->add('clearSecrets', ChoiceType::class, [
                'mapped'       => false,
                'required'     => false,
                'multiple'     => true,
                'expanded'     => true,
                'label'        => 'vault.form.clear_secrets',
                'choices'      => array_combine($storedSecrets, $storedSecrets),
                'choice_label' => static fn (string $key): string => self::SECRET_LABELS[$key] ?? 'vault.form.' . $key,
            ]);
        }

        // REQ-TWIG-005: identity card scan, read by the controller from the request files as "attachment".
        $builder->add('attachment', FileType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'vault.form.attachment',
            'attr'     => ['class' => 'nowo-ui-input', 'accept' => '.pdf,.jpg,.jpeg,.png,.webp'],
        ]);
    }

    /**
     * Keeps the historical flat {@code attachment} upload name the controller reads.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (isset($view->children['attachment'])) {
            $view->children['attachment']->vars['full_name'] = 'attachment';
        }
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
                'input_class'        => 'nowo-ui-input',
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

    /**
     * @param array<string, mixed> $secretOptions placeholder / data attribute when a password is stored
     * @param bool $strengthMeter false (1.18.0: view page, read-only grant) = a plain password field without the
     *                            PasswordStrength meter, which would list every rule as unmet for a value it cannot see
     */
    private function addItemPasswordField(array $secretOptions, bool $strengthMeter = true): void
    {
        $passwordFieldConfig = $this->runtimeConfig->get()['password_field'];
        $level               = (string) ($passwordFieldConfig['level'] ?? 'medium');

        if ($strengthMeter && PasswordStrengthIntegration::isAvailable()) {
            $passwordType   = PasswordStrengthIntegration::PASSWORD_STRENGTH_TYPE;
            $validatorClass = PasswordStrengthIntegration::PASSWORD_STRENGTH_VALIDATOR;

            $options = [
                'required'            => false,
                'empty_data'          => '',
                'label'               => 'vault.form.password',
                'translation_domain'  => 'NowoVaultBundle',
                'always_empty'        => true,
                'ui_framework'        => 'bootstrap5',
                'level'               => $level,
                'generator_mode'      => $passwordFieldConfig['generator_mode'] ?? 'input',
                'use_password_toggle' => $passwordFieldConfig['use_password_toggle'] ?? true,
            ];
            if (PasswordStrengthIntegration::supportsUiTranslationDomain()) {
                // The widget copy (requirements, generator button) lives in the PasswordStrength catalogue.
                $options['ui_translation_domain'] = 'NowoPasswordStrengthBundle';
                // @phpstan-ignore class.notFound (optional PasswordStrengthBundle validator)
                $options['constraints'] = [new $validatorClass(policyMode: 'level', level: $level)];
            } else {
                // @phpstan-ignore class.notFound (optional PasswordStrengthBundle validator)
                $options['constraints'] = [new $validatorClass(['policyMode' => 'level', 'level' => $level])];
            }

            /** @var list<object> $policyConstraints */
            $policyConstraints = $secretOptions['constraints'] ?? [];
            unset($secretOptions['constraints']);
            $options['constraints'] = [...$options['constraints'], ...$policyConstraints];

            // @phpstan-ignore argument.type (optional PasswordStrengthBundle form type resolved at runtime)
            $this->addTypedField('password', $passwordType, array_merge($options, $secretOptions));

            return;
        }

        $this->addPasswordField('password', array_merge([
            'required'     => false,
            'empty_data'   => '',
            'label'        => 'vault.form.password',
            'always_empty' => true,
        ], $secretOptions));
    }

    /**
     * Configures the data class, translation domain and the folders option.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => VaultItemFormData::class,
            'translation_domain' => 'NowoVaultBundle',
            'folders'            => [],
            // Secret payload keys holding a stored value (placeholder + reveal hook), see VaultItemFormData.
            'stored_secret_fields' => [],
            // Edit form only: offer the "clear stored values" choices.
            'allow_secret_clear' => false,
            // 1.16.0: scope item (catalogue help on the title, catalogue_hidden choice).
            'scope_item' => false,
            // 1.16.0: VaultPasswordPolicy of the item owner (validated when enforceOnSave).
            'password_policy' => null,
            // 1.18.0: PasswordStrength meter on the password field (when installed); false on the view page.
            'strength_meter' => true,
        ]);
        $resolver->setAllowedTypes('strength_meter', 'bool');
        $resolver->setAllowedTypes('password_policy', ['null', VaultPasswordPolicy::class]);
        $resolver->setAllowedTypes('scope_item', 'bool');
        $resolver->setAllowedTypes('folders', 'array');
        $resolver->setAllowedTypes('stored_secret_fields', 'string[]');
        $resolver->setAllowedTypes('allow_secret_clear', 'bool');
    }
}
