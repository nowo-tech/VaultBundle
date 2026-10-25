<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * New folder (REQ-TWIG-005): flat {@code name} and {@code _token} (token id {@code vault_folder_create}).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultFolderCreateType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'vault_folder_create';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'required' => true,
            'label'    => false,
            'attr'     => ['class' => 'form-control', 'placeholder' => 'vault.folder.name_placeholder'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoVaultBundle',
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
