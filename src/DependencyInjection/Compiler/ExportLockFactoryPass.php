<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;

/**
 * Fails the compile when `export.lock_factory` names a service that does not exist (SEC-004 FQ-I13): a typo would
 * otherwise silently drop the per-user export lock. The default (`lock.factory`) may be missing: then the export runs
 * without the lock, as documented.
 */
final class ExportLockFactoryPass implements CompilerPassInterface
{
    /** Default value of `nowo_vault.export.lock_factory` (optional: FrameworkBundle registers it with symfony/lock). */
    public const DEFAULT_ID = 'lock.factory';

    /**
     * Throws when an explicit, non-default lock factory id does not resolve.
     */
    public function process(ContainerBuilder $container): void
    {
        $id = $container->hasParameter('nowo_vault.export.lock_factory') ? $container->getParameter('nowo_vault.export.lock_factory') : null;
        if (!is_string($id) || $id === '' || $id === self::DEFAULT_ID || $container->has($id)) {
            return;
        }

        throw new LogicException(sprintf('nowo_vault.export.lock_factory is "%s", but no such service exists. Set it to a Symfony Lock factory service id (composer require symfony/lock; framework.lock), leave the default "%s", or set it to null to run the export without a lock.', $id, self::DEFAULT_ID));
    }
}
