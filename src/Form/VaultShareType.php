<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
 * @extends AbstractType<VaultShareFormData>
 */
final class VaultShareType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<VaultGranteeChoice> $granteeChoices */
        $granteeChoices = $options['grantee_choices'];

        if ($granteeChoices !== []) {
            $builder->add('granteeSelection', ChoiceType::class, [
                'mapped'      => false,
                'choices'     => $this->buildGroupedChoices($granteeChoices),
                'placeholder' => 'vault.share.grantee_placeholder',
                'constraints' => [new NotBlank()],
                'label'       => 'vault.share.grantee',
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

            $builder->add('permission', EnumType::class, [
                'class' => VaultPermission::class,
                'label' => 'vault.share.permission',
            ]);

            return;
        }

        $builder
            ->add('granteeType', EnumType::class, [
                'class' => GranteeType::class,
                'label' => 'vault.share.grantee_type',
            ])
            ->add('granteeId', TextType::class, [
                'constraints' => [new NotBlank()],
                'label'       => 'vault.share.grantee_id',
            ])
            ->add('permission', EnumType::class, [
                'class' => VaultPermission::class,
                'label' => 'vault.share.permission',
            ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['grantee_picker'] = $options['grantee_choices'] !== [];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => VaultShareFormData::class,
            'translation_domain' => 'NowoVaultBundle',
            'grantee_choices'    => [],
        ]);
        $resolver->setAllowedTypes('grantee_choices', 'array');
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
