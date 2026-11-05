<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\ExportLockFactoryPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * SEC-004 FQ-I13: an explicit export.lock_factory that does not resolve fails the compile; the default may be absent.
 */
final class ExportLockFactoryPassTest extends TestCase
{
    public function testMissingDefaultNullOrExistingServicesPass(): void
    {
        foreach ([null, '', ExportLockFactoryPass::DEFAULT_ID, 'app.lock_factory'] as $id) {
            $container = new ContainerBuilder();
            $container->register('app.lock_factory', stdClass::class);
            $container->setParameter('nowo_vault.export.lock_factory', $id);
            (new ExportLockFactoryPass())->process($container);
        }
        (new ExportLockFactoryPass())->process(new ContainerBuilder());
        self::addToAssertionCount(5);
    }

    public function testAnExplicitMissingServiceFailsTheCompile(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_vault.export.lock_factory', 'app.lokc_factory');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_vault.export.lock_factory is "app.lokc_factory", but no such service exists');
        (new ExportLockFactoryPass())->process($container);
    }
}
