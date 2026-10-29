<?php

declare(strict_types=1);

namespace Nowo\TagInputBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Minimal stand-in for nowo-tech/tag-input-bundle's TagType (not installed in this repo), loaded only by
 * tests that run in a separate process to exercise VaultItemFormType's optional integration branch.
 */
final class TagType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined(['input_class', 'placeholder', 'duplicates', 'dropdown_enabled']);
    }
}
