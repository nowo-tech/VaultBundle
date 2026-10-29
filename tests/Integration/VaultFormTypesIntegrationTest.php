<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Form\VaultActionType;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Form\VaultItemSearchType;
use Nowo\VaultBundle\Form\VaultRuntimeConfigType;
use Nowo\VaultBundle\Form\VaultShareType;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;

use function array_keys;

/**
 * Bundle form types built through the test kernel form factory (FormKit, translator, CSRF wired as in a host).
 */
final class VaultFormTypesIntegrationTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);
        $this->formFactory = $formFactory;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testActionTypeAddsOnlyNonEmptyHiddenFields(): void
    {
        $form = $this->formFactory->createNamed('', VaultActionType::class, null, [
            'csrf_token_id' => 'vault_trash_1',
            'hidden_fields' => ['folder' => 'f-1', 'q' => '', 'tag' => null],
        ]);

        self::assertTrue($form->has('folder'));
        self::assertFalse($form->has('q'));
        self::assertFalse($form->has('tag'));
        self::assertSame(HiddenType::class, $form->get('folder')->getConfig()->getType()->getInnerType()::class);
        self::assertSame('f-1', $form->get('folder')->getData());
        self::assertSame('', $form->getConfig()->getType()->getBlockPrefix());
    }

    public function testSearchTypeKeepsActiveFiltersAsHiddenFields(): void
    {
        $form = $this->formFactory->create(VaultItemSearchType::class, ['q' => 'bank'], [
            'filters' => ['folder' => 'f-1', 'tag' => ''],
        ]);

        self::assertTrue($form->has('folder'));
        self::assertFalse($form->has('tag'));
        self::assertSame('f-1', $form->get('folder')->getData());

        $view = $form->createView();
        self::assertSame('vault-search', $view->children['q']->vars['id']);
    }

    public function testShareTypeWithoutChoicesUsesFreeGranteeFields(): void
    {
        $data = new VaultShareFormData();
        $form = $this->formFactory->create(VaultShareType::class, $data, ['csrf_protection' => false]);

        self::assertTrue($form->has('granteeType'));
        self::assertTrue($form->has('granteeId'));
        self::assertFalse($form->has('granteeSelection'));
        self::assertFalse($form->createView()->vars['grantee_picker']);

        $form->submit(['granteeType' => 'team', 'granteeId' => 'team-a', 'permission' => 'write']);

        self::assertTrue($form->isValid());
        self::assertSame(GranteeType::Team, $data->granteeType);
        self::assertSame('team-a', $data->granteeId);
        self::assertSame(VaultPermission::Write, $data->permission);
    }

    public function testShareTypeGranteePickerGroupsChoicesAndMapsSelection(): void
    {
        $data = new VaultShareFormData();
        $form = $this->formFactory->create(VaultShareType::class, $data, [
            'csrf_protection' => false,
            'grantee_choices' => [
                new VaultGranteeChoice(GranteeType::User, '42', 'Bob'),
                new VaultGranteeChoice(GranteeType::Team, 'ops', 'Ops team'),
            ],
        ]);

        self::assertTrue($form->has('granteeSelection'));
        self::assertFalse($form->has('granteeId'));
        self::assertSame(EnumType::class, $form->get('permission')->getConfig()->getType()->getInnerType()::class);
        $choices = $form->get('granteeSelection')->getConfig()->getOption('choices');
        self::assertSame(['User' => ['Bob' => 'user:42'], 'Team / group' => ['Ops team' => 'team:ops']], $choices);
        self::assertTrue($form->createView()->vars['grantee_picker']);

        $form->submit(['granteeSelection' => 'team:ops', 'permission' => 'admin']);

        self::assertTrue($form->isValid());
        self::assertSame(GranteeType::Team, $data->granteeType);
        self::assertSame('ops', $data->granteeId);
        self::assertSame(VaultPermission::Admin, $data->permission);
    }

    public function testShareTypeGranteePickerOmitsEmptyGroupsAndIgnoresEmptySelection(): void
    {
        $data = new VaultShareFormData();
        $form = $this->formFactory->create(VaultShareType::class, $data, [
            'csrf_protection' => false,
            'grantee_choices' => [new VaultGranteeChoice(GranteeType::User, '42', 'Bob')],
        ]);

        self::assertSame(['User'], array_keys($form->get('granteeSelection')->getConfig()->getOption('choices')));

        $form->submit(['granteeSelection' => '', 'permission' => 'read']);

        self::assertFalse($form->isValid());
        self::assertSame(GranteeType::User, $data->granteeType);
        self::assertSame('', $data->granteeId);
    }

    public function testShareTypeSelectionListenerToleratesMissingFieldOrForeignData(): void
    {
        $choices = ['grantee_choices' => [new VaultGranteeChoice(GranteeType::User, '42', 'Bob')], 'csrf_protection' => false];

        // Field removed by the host before submit: the listener leaves the data untouched.
        $data = new VaultShareFormData();
        $form = $this->formFactory->create(VaultShareType::class, $data, $choices);
        $form->remove('granteeSelection');
        $form->submit(['permission' => 'read']);
        self::assertSame('', $data->granteeId);

        // A host reusing the type with array data (no data_class): nothing is mapped onto it.
        $form = $this->formFactory->create(VaultShareType::class, null, ['data_class' => null] + $choices);
        $form->submit(['granteeSelection' => 'user:42', 'permission' => 'read']);
        self::assertSame(['permission' => VaultPermission::Read], $form->getData());
    }

    public function testItemFormDropsBlankTagsOnSubmit(): void
    {
        $data = new VaultItemFormData();
        $form = $this->formFactory->create(VaultItemFormType::class, $data, ['csrf_protection' => false]);

        self::assertSame(CollectionType::class, $form->get('tags')->getConfig()->getType()->getInnerType()::class);

        $form->submit(['title' => 'Bank', 'tags' => ['work', '  ', '', 'home']]);

        self::assertTrue($form->isValid());
        self::assertSame(['work', 'home'], $data->tags);
    }

    public function testItemFormKeepsFlatAttachmentUploadName(): void
    {
        $view = $this->formFactory->create(VaultItemFormType::class, new VaultItemFormData(), ['csrf_protection' => false])->createView();

        self::assertSame('attachment', $view->children['attachment']->vars['full_name']);
        self::assertSame('vault_item_form[title]', $view->children['title']->vars['full_name']);
    }

    public function testItemFormTagListenerIgnoresArrayData(): void
    {
        $form = $this->formFactory->create(VaultItemFormType::class, null, ['csrf_protection' => false, 'data_class' => null]);
        $form->submit(['title' => 'Bank', 'tags' => ['work', '']]);

        // Blank entries are only filtered for VaultItemFormData.
        $submitted = $form->getData();
        self::assertIsArray($submitted);
        self::assertSame(['work', ''], $submitted['tags']);
    }

    public function testRuntimeConfigEditableModePostsEncryptionKey(): void
    {
        $form = $this->formFactory->createNamed('', VaultRuntimeConfigType::class, null, ['csrf_protection' => false]);
        $key  = $form->get('encryption_key')->getConfig();

        self::assertFalse($key->getOption('disabled'));
        self::assertSame('vault.runtime_config.encryption_key_placeholder', $key->getOption('attr')['placeholder']);
        self::assertArrayNotHasKey('readonly', $key->getOption('attr'));

        $view = $form->createView();
        self::assertSame('encryption_key', $view->children['encryption_key']->vars['id']);
        self::assertSame('max_attachment_bytes', $view->children['max_attachment_bytes']->vars['id']);
        self::assertSame('encryption_key', $view->children['encryption_key']->vars['full_name']);
        self::assertSame(['weak' => 'weak', 'medium' => 'medium', 'strong' => 'strong'], $form->get('password_level')->getConfig()->getOption('choices'));
    }

    public function testRuntimeConfigOneTimeModeShowsReadOnlyKeyThatIsNeverPosted(): void
    {
        $form = $this->formFactory->createNamed('', VaultRuntimeConfigType::class, null, [
            'csrf_protection'        => false,
            'encryption_key_mode'    => 'one_time',
            'encryption_key_display' => 'REDACTED-TEST',
        ]);
        $key = $form->get('encryption_key');

        self::assertSame('REDACTED-TEST', $key->getData());
        self::assertTrue($key->getConfig()->getOption('attr')['readonly']);
        self::assertFalse($key->getConfig()->getOption('disabled'));
        self::assertSame('', $form->createView()->children['encryption_key']->vars['full_name']);
    }

    public function testRuntimeConfigConfiguredModeDisablesKeyField(): void
    {
        $form = $this->formFactory->createNamed('', VaultRuntimeConfigType::class, null, ['encryption_key_mode' => 'configured']);
        $key  = $form->get('encryption_key')->getConfig();

        self::assertTrue($key->getOption('disabled'));
        self::assertTrue($key->getOption('attr')['readonly']);
        self::assertSame('vault.runtime_config.encryption_key_configured', $key->getOption('attr')['placeholder']);
        self::assertSame('', $form->get('encryption_key')->getData());
    }

    public function testRuntimeConfigUnavailableModeOmitsKeyField(): void
    {
        $form = $this->formFactory->createNamed('', VaultRuntimeConfigType::class, null, ['csrf_protection' => false, 'encryption_key_mode' => 'unavailable']);

        self::assertFalse($form->has('encryption_key'));
        self::assertTrue($form->has('max_attachment_bytes'));
        self::assertArrayNotHasKey('encryption_key', $form->createView()->children);
        self::assertSame(VaultRuntimeConfigType::CSRF_TOKEN_ID, $form->getConfig()->getOption('csrf_token_id'));
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }
}
