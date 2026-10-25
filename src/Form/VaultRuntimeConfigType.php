<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;

/**
 * Runtime configuration page (REQ-TWIG-005): flat {@code encryption_key}, {@code max_attachment_bytes},
 * {@code password_level} and {@code _token} (token id {@code vault_runtime_config}), as before.
 *
 * {@code encryption_key_mode}: {@code editable} (posted), {@code one_time} (read-only display of a freshly generated
 * key) or {@code configured} (disabled placeholder); the last two are display-only and never posted.
 * {@code unavailable} omits the field.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('vault')]
final class VaultRuntimeConfigType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'vault_runtime_config';

    public const PASSWORD_LEVELS = ['weak', 'medium', 'strong'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mode = $options['encryption_key_mode'];

        if ($mode !== 'unavailable') {
            $attr = ['class' => 'form-control font-monospace', 'autocomplete' => 'off', 'spellcheck' => 'false'];
            if ($mode !== 'editable') {
                $attr['readonly'] = true;
            }
            if ($mode === 'configured') {
                $attr['placeholder'] = 'vault.runtime_config.encryption_key_configured';
            }
            if ($mode === 'editable') {
                $attr['placeholder'] = 'vault.runtime_config.encryption_key_placeholder';
            }

            $builder->add('encryption_key', TextType::class, [
                'mapped'     => false,
                'required'   => false,
                'disabled'   => $mode === 'configured',
                'label'      => 'vault.runtime_config.encryption_key',
                'label_attr' => ['class' => 'form-label'],
                'data'       => $mode === 'one_time' ? $options['encryption_key_display'] : '',
                'attr'       => $attr,
            ]);
        }

        $builder->add('max_attachment_bytes', IntegerType::class, [
            'required'   => true,
            'label'      => 'vault.runtime_config.max_attachment_bytes',
            'label_attr' => ['class' => 'form-label'],
            'row_attr'   => ['class' => 'mb-3'],
            'attr'       => ['class' => 'form-control', 'min' => 0, 'step' => 1000],
        ]);
        $builder->add('password_level', ChoiceType::class, [
            'label'                     => 'vault.runtime_config.password_level',
            'label_attr'                => ['class' => 'form-label'],
            'row_attr'                  => ['class' => 'mb-3'],
            'choices'                   => array_combine(self::PASSWORD_LEVELS, self::PASSWORD_LEVELS),
            'choice_translation_domain' => false,
            'attr'                      => ['class' => 'form-select'],
        ]);
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        foreach ($view->children as $name => $child) {
            $child->vars['id'] = $name;
        }

        if (isset($view->children['encryption_key']) && $options['encryption_key_mode'] !== 'editable') {
            $view->children['encryption_key']->vars['full_name'] = '';
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'        => true,
            'csrf_field_name'        => '_token',
            'csrf_token_id'          => self::CSRF_TOKEN_ID,
            'allow_extra_fields'     => true,
            'translation_domain'     => 'NowoVaultBundle',
            'encryption_key_mode'    => 'editable',
            'encryption_key_display' => '',
        ]);
        $resolver->setAllowedValues('encryption_key_mode', ['editable', 'one_time', 'configured', 'unavailable']);
        $resolver->setAllowedTypes('encryption_key_display', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
