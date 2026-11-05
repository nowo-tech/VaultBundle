<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Decrypted personal export (1.16.0, REQ-TWIG-005): flat {@code format} (one of `export.formats`) and {@code _token}
 * (token id {@code vault_personal_export}).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultPersonalExportType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'vault_personal_export';

    /**
     * Adds the format choice.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $formats */
        $formats = $options['formats'];
        $choices = [];
        foreach ($formats as $format) {
            $choices['vault.export.format_' . $format] = $format;
        }

        $builder->add('format', ChoiceType::class, [
            'label'    => 'vault.export.format',
            'choices'  => $choices,
            'expanded' => true,
            'required' => true,
        ]);
    }

    /**
     * Configures CSRF protection and the offered formats.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => 'NowoVaultBundle',
            'formats'            => ['json', 'csv'],
        ]);
        $resolver->setAllowedTypes('formats', 'string[]');
    }

    /**
     * Returns an empty block prefix so fields keep their plain names.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
