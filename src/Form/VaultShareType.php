<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

/**
 * Form to share a vault item or folder with a user or team grantee.
 *
 * @extends AbstractType<VaultShareFormData>
 */
#[FormKitConfig('vault')]
final class VaultShareType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Creates the form type with the translator.
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Adds the grantee picker (or free grantee type/id fields) and the permission field.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<VaultGranteeChoice> $granteeChoices */
        $granteeChoices = $options['grantee_choices'];
        if ($options['temporary_ui']) {
            $this->addExpiryFields($builder);
        }

        if ($granteeChoices !== []) {
            $this->addChoice($builder, 'granteeSelection', [
                'mapped'      => false,
                'choices'     => $this->buildGroupedChoices($granteeChoices),
                'placeholder' => 'vault.share.grantee_placeholder',
                'constraints' => [new NotBlank()],
                'label'       => 'vault.share.grantee',
                // Group labels are translated by buildGroupedChoices(), choice labels are host data (user / team names).
                'choice_translation_domain' => false,
            ]);
            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $form = $event->getForm();
                if (!$form->has('granteeSelection')) {
                    return;
                }

                $data = $event->getData();
                if (!$data instanceof VaultShareFormData) {
                    return;
                }

                $selection = $form->get('granteeSelection')->getData();
                if (!is_string($selection) || $selection === '') {
                    return;
                }

                [$type, $id]       = explode(':', $selection, 2);
                $data->granteeType = GranteeType::from($type);
                $data->granteeId   = $id;
            });

            $this->addWithDefaults($builder, 'permission', EnumType::class, [
                'class'        => VaultPermission::class,
                'label'        => 'vault.share.permission',
                'choice_label' => static fn (VaultPermission $permission): string => 'vault.permission.' . $permission->value,
            ]);

            return;
        }

        $this->addWithDefaults($builder, 'granteeType', EnumType::class, [
            'class'        => GranteeType::class,
            'label'        => 'vault.share.grantee_type',
            'choice_label' => static fn (GranteeType $type): string => 'vault.grantee_type.' . $type->value,
        ]);
        $this->addText($builder, 'granteeId', [
            'constraints' => [new NotBlank()],
            'label'       => 'vault.share.grantee_id',
        ]);
        $this->addWithDefaults($builder, 'permission', EnumType::class, [
            'class'        => VaultPermission::class,
            'label'        => 'vault.share.permission',
            'choice_label' => static fn (VaultPermission $permission): string => 'vault.permission.' . $permission->value,
        ]);
    }

    /**
     * Exposes whether the grantee picker is used to the template.
     */
    /**
     * Optional expiry of the grant (1.16.0, `grants.temporary.ui`): permanent, 1 hour, 1 day, 1 week or a custom date
     * (the service refuses anything outside `grants.temporary.min_ttl` / `max_ttl`).
     */
    /**
     * @param FormBuilderInterface<VaultShareFormData|null> $builder
     */
    private function addExpiryFields(FormBuilderInterface $builder): void
    {
        $builder->add('expiresIn', ChoiceType::class, [
            'required'    => false,
            'label'       => 'vault.share.expires_in',
            'placeholder' => 'vault.share.expires_never',
            'choices'     => [
                'vault.share.expires_1h'     => 'PT1H',
                'vault.share.expires_1d'     => 'P1D',
                'vault.share.expires_1w'     => 'P7D',
                'vault.share.expires_custom' => 'custom',
            ],
        ]);
        $builder->add('expiresAt', DateTimeType::class, [
            'required' => false,
            'widget'   => 'single_text',
            'input'    => 'datetime_immutable',
            'label'    => 'vault.share.expires_at',
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['grantee_picker'] = $options['grantee_choices'] !== [];
    }

    /**
     * Configures the data class, translation domain and the grantee_choices option.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => VaultShareFormData::class,
            'translation_domain' => 'NowoVaultBundle',
            'grantee_choices'    => [],
            'temporary_ui'       => false,
        ]);
        $resolver->setAllowedTypes('grantee_choices', 'array');
        $resolver->setAllowedTypes('temporary_ui', 'bool');
    }

    /**
     * @param list<VaultGranteeChoice> $granteeChoices
     *
     * @return array<string, array<string, string>>
     */
    private function buildGroupedChoices(array $granteeChoices): array
    {
        $grouped = [
            $this->translator->trans('vault.grantee_type.user', [], 'NowoVaultBundle') => [],
            $this->translator->trans('vault.grantee_type.team', [], 'NowoVaultBundle') => [],
        ];

        foreach ($granteeChoices as $choice) {
            $group = $choice->type === GranteeType::User
                ? $this->translator->trans('vault.grantee_type.user', [], 'NowoVaultBundle')
                : $this->translator->trans('vault.grantee_type.team', [], 'NowoVaultBundle');
            $grouped[$group][$choice->label] = $choice->getKey();
        }

        return array_filter($grouped, static fn (array $choices): bool => $choices !== []);
    }
}
