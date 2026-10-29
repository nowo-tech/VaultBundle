<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Composer\InstalledVersions;
use Nowo\PasswordStrengthBundle\Form\PasswordStrengthType;
use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use Nowo\TagInputBundle\Form\TagType;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * VaultItemFormType with the optional TagInput / PasswordStrength bundles present. Those packages are not
 * installed here, so minimal stand-ins (tests/Fixtures/OptionalBundles) are loaded in a separate process:
 * defining the classes in the main process would switch every other test to the optional branches.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VaultItemFormTypeOptionalBundlesIntegrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Fixtures/OptionalBundles/TagType.php';
        require_once __DIR__ . '/../Fixtures/OptionalBundles/PasswordStrengthType.php';
        require_once __DIR__ . '/../Fixtures/OptionalBundles/PasswordStrength.php';
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testUsesTagInputAndLegacyPasswordStrengthConstraint(): void
    {
        self::assertFalse(PasswordStrengthIntegration::supportsUiTranslationDomain());

        $form = $this->createItemForm();

        $tags = $form->get('tags')->getConfig();
        self::assertSame(TagType::class, $tags->getType()->getInnerType()::class);
        self::assertSame('form-control', $tags->getOption('input_class'));
        self::assertFalse($tags->getOption('duplicates'));
        self::assertTrue($tags->getOption('dropdown_enabled'));
        // Translated by the bundle catalogue, never the raw key.
        self::assertNotSame('vault.form.tag_placeholder', $tags->getOption('placeholder'));
        self::assertNotSame('', $tags->getOption('placeholder'));

        $password = $form->get('password')->getConfig();
        self::assertSame(PasswordStrengthType::class, $password->getType()->getInnerType()::class);
        self::assertSame('medium', $password->getOption('level'));
        self::assertSame('input', $password->getOption('generator_mode'));
        self::assertTrue($password->getOption('use_password_toggle'));
        self::assertNull($password->getOption('ui_translation_domain'));

        $constraint = $password->getOption('constraints')[0];
        self::assertInstanceOf(PasswordStrength::class, $constraint);
        self::assertTrue($constraint->legacyOptions);
        self::assertSame('level', $constraint->policyMode);
        self::assertSame('medium', $constraint->level);
    }

    public function testUsesUiTranslationDomainAndNamedConstraintFromVersionTwoEight(): void
    {
        $data = InstalledVersions::getAllRawData()[0];
        // Registered as installed only inside this process.
        $data['versions'][PasswordStrengthIntegration::PACKAGE] = [
            'pretty_version'  => '2.10.0',
            'version'         => '2.10.0.0',
            'reference'       => null,
            'type'            => 'symfony-bundle',
            'install_path'    => __DIR__,
            'aliases'         => [],
            'dev_requirement' => true,
        ];
        InstalledVersions::reload($data);
        self::assertTrue(PasswordStrengthIntegration::supportsUiTranslationDomain());

        $password = $this->createItemForm()->get('password')->getConfig();

        self::assertSame('NowoPasswordStrengthBundle', $password->getOption('ui_translation_domain'));
        $constraint = $password->getOption('constraints')[0];
        self::assertInstanceOf(PasswordStrength::class, $constraint);
        self::assertFalse($constraint->legacyOptions);
        self::assertSame('level', $constraint->policyMode);
        self::assertSame('medium', $constraint->level);
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function createItemForm(): FormInterface
    {
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(VaultItemFormType::class, new VaultItemFormData(), ['csrf_protection' => false]);
    }
}
