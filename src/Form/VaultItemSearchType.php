<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

/**
 * Item list search (REQ-TWIG-005): GET, no CSRF, flat {@code q} plus the active {@code folder} / {@code tag} filters
 * as hidden fields (same query names as before, so bookmarks keep working).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultItemSearchType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the search field and the active folder / tag filters as hidden fields.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['folder', 'tag'] as $filter) {
            $value = $options['filters'][$filter] ?? null;
            if (is_string($value) && $value !== '') {
                $builder->add($filter, HiddenType::class, ['mapped' => false, 'data' => $value]);
            }
        }

        $builder->add('q', SearchType::class, [
            'required'   => false,
            'label'      => 'vault.search.placeholder',
            'label_attr' => ['class' => 'form-label'],
            'attr'       => ['class' => 'form-control', 'placeholder' => 'vault.search.placeholder_tags'],
        ]);
    }

    /**
     * Gives the search input a stable DOM id.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->children['q']->vars['id'] = 'vault-search';
    }

    /**
     * Configures a GET form without CSRF and with the filters option.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoVaultBundle',
            'filters'            => [],
        ]);
        $resolver->setAllowedTypes('filters', 'array');
    }

    /**
     * Returns an empty block prefix so query parameters keep their plain names.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
