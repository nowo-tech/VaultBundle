<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\VaultBundle\Controller\VaultCsrfTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only row / toolbar action (trash, restore, purge, revoke grant, trash folder, delete tag) — REQ-TWIG-005.
 *
 * The empty block prefix keeps every posted name flat: {@code _token} (same token ids as before, still validated by
 * {@see VaultCsrfTrait}, which also accepts the header / JSON token of API clients) and
 * the optional {@code hidden_fields} (e.g. the list filters a tag delete redirects back to).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the configured hidden fields (skipping empty values) to the single-button action form.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string|null> $hidden */
        $hidden = $options['hidden_fields'];
        foreach ($hidden as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $builder->add($name, HiddenType::class, ['mapped' => false, 'data' => $value]);
        }
    }

    /**
     * Configures CSRF protection and the hidden_fields / csrf_token_id options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
            'hidden_fields'      => [],
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setAllowedTypes('hidden_fields', 'array');
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
