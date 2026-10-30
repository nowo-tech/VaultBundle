<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Cross-bundle pattern P2: the manage UI refuses to compile without CSRF protection.
 */
final class CsrfProtectionPassTest extends TestCase
{
    public function testIgnoredWithoutTheManageUi(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);

        $container->setParameter('nowo_vault.web_ui.enabled', false);
        (new CsrfProtectionPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testPassesWithFormCsrfProtection(): void
    {
        (new CsrfProtectionPass())->process($this->container(true, true, true));
        (new CsrfProtectionPass())->process($this->container(true, true, null));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{bool, bool, bool|null}>
     */
    public static function unprotected(): iterable
    {
        yield 'no token manager' => [false, true, null];
        yield 'no form CSRF extension' => [true, false, null];
        yield 'form CSRF disabled' => [true, true, false];
    }

    #[DataProvider('unprotected')]
    public function testFailsClosedWithoutCsrfProtection(bool $tokenManager, bool $formExtension, ?bool $enabled): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new CsrfProtectionPass())->process($this->container($tokenManager, $formExtension, $enabled));
    }

    private function container(bool $tokenManager, bool $formExtension, ?bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_vault.web_ui.enabled', true);
        if ($tokenManager) {
            $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
        }
        if ($formExtension) {
            $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));
        }
        if ($enabled !== null) {
            $container->setParameter('form.type_extension.csrf.enabled', $enabled);
        }

        return $container;
    }
}
