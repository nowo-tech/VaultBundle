<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_DEPRECATED;

/**
 * 2.0: a service the application still registers under, aliases to, decorates or implements a removed port id fails the
 * build with a message naming the service and the port to implement instead (REQ-ARCH-001, "a removed host-facing path
 * fails loudly").
 */
final class RemovedPortsPassTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function removedPorts(): iterable
    {
        foreach (RemovedPortsPass::REMOVED_PORTS as $port => $twin) {
            yield $port => [$port, $twin];
        }
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function anAliasOfARemovedPortToAClassWithoutTheTwinFailsTheBuild(string $port, string $twin): void
    {
        $container = new ContainerBuilder();
        $container->register('app.service', stdClass::class);
        $container->setAlias($port, 'app.service');

        $this->assertFails($container, $port, $twin, ['it is aliased to "app.service", whose class does not implement "' . $twin . '": make it implement']);

        $unknown = new ContainerBuilder();
        $unknown->register('app.factory', stdClass::class)->setFactory('app_factory');
        $unknown->setAlias($port, 'app.factory');
        $this->assertFails($unknown, $port, $twin, ['it is aliased to "app.factory"'], 'a class unknown before compilation is never accepted');

        $missing = new ContainerBuilder();
        $missing->setAlias($port, 'app.missing');
        $this->assertFails($missing, $port, $twin, ['it is aliased to "app.missing"'], 'an alias of a service that does not exist is never accepted');
    }

    /**
     * Audit VT200-M1: an alias of a removed id (the 1.17 way) with a class implementing the twin keeps working until 3.0.
     */
    #[Test]
    #[DataProvider('removedPorts')]
    public function anAliasOfARemovedIdWithTheTwinIsAcceptedWithADeprecation(string $port, string $twin): void
    {
        $class = $this->twinClass($twin);

        $alias = new ContainerBuilder();
        $alias->register('app.directory', $class);
        $alias->setAlias('app.directory_alias', 'app.directory');
        $alias->setAlias($port, 'app.directory_alias');
        $messages = $this->deprecations(static fn () => (new RemovedPortsPass())->process($alias));
        self::assertSame('app.directory', (string) $alias->getAlias($twin));
        self::assertCount(1, $messages);
        self::assertSame(sprintf('Since nowo-tech/vault-bundle 2.0: The service id "%s" was removed in 2.0; the vault uses "app.directory" (which implements "%s") through that interface. Alias "%s" instead (the old id is accepted until 3.0).', $port, $twin, $twin), $messages[0]);

        // Audit VT200-M2: a service registered under the removed id was never used by 1.21 (its own alias of the id
        // replaced it, the built-in served the port): adopting it now would change access. The build fails.
        $service = new ContainerBuilder();
        $service->register($port, $class);
        $this->assertFails($service, $port, $twin, ['a service is registered under it, which 1.21 did not use (the built-in served the port): alias "' . $twin . '" to it to use it, or remove it']);
        self::assertFalse($service->hasAlias($twin), 'never adopted');

        // The application aliases the twin to the same service already: kept as it is.
        $same = new ContainerBuilder();
        $same->register('app.directory', $class);
        $same->setAlias($port, 'app.directory');
        $same->setAlias($twin, 'app.directory');
        self::assertCount(1, $this->deprecations(static fn () => (new RemovedPortsPass())->process($same)));
        self::assertSame('app.directory', (string) $same->getAlias($twin));
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function theTwinAliasedToAnotherServiceFailsTheBuild(string $port, string $twin): void
    {
        $class     = $this->twinClass($twin);
        $container = new ContainerBuilder();
        $container->register('app.directory', $class);
        $container->setAlias($port, 'app.directory');
        $container->setDefinition($twin, new Definition($class));

        $this->assertFails($container, $port, $twin, ['it points at "app.directory" while "' . $twin . '" points at "' . $twin . '": keep only the alias of "' . $twin . '"']);
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function aDefinitionUnderOrDecoratingARemovedPortFailsTheBuild(string $port, string $twin): void
    {
        $container = new ContainerBuilder();
        $container->register($port, stdClass::class);
        $container->register('app.audit', stdClass::class)->setDecoratedService($port);

        $this->assertFails($container, $port, $twin, ['a service is registered under it, which 1.21 did not use (the built-in served the port): alias "' . $twin . '" to it to use it, or remove it', '"app.audit" decorates it: decorate "' . $twin . '" instead']);
    }

    /**
     * An application class that still implements a removed port cannot be loaded; resource discovery keeps its
     * definition with only a loading error (and Symfony would drop it as unused). The build fails.
     */
    #[Test]
    #[DataProvider('removedPorts')]
    public function aDefinitionThatCannotLoadBecauseOfARemovedPortFailsTheBuild(string $port, string $twin): void
    {
        $container = new ContainerBuilder();
        $container->register('App\\Vault\\LegacyService')->addError(sprintf('Class "%s" not found while loading "App\\Vault\\LegacyService".', $port));

        $this->assertFails($container, $port, $twin, ['the class of "App\\Vault\\LegacyService" still implements it and cannot be loaded: implement only "' . $twin . '"']);
    }

    #[Test]
    public function otherWiringIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->register('App\\Broken')->addError('Class "App\\Missing" not found while loading "App\\Broken".');
        $container->register('app.audit', stdClass::class)->setDecoratedService('app.other');

        (new RemovedPortsPass())->process($container);

        self::assertTrue($container->hasDefinition('App\\Broken'), 'a loading error about another class is left to Symfony');
    }

    /**
     * @param list<string> $parts
     */
    private function assertFails(ContainerBuilder $container, string $port, string $twin, array $parts, string $why = ''): void
    {
        try {
            (new RemovedPortsPass())->process($container);
            self::fail('the build must fail ' . $why);
        } catch (LogicException $exception) {
            self::assertStringContainsString('"' . $port . '" was removed in 2.0 (deprecated since 1.19), but the application still uses it: ', $exception->getMessage());
            foreach ($parts as $part) {
                self::assertStringContainsString($part, $exception->getMessage());
            }
            self::assertStringContainsString($twin, $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        }
    }

    /**
     * A test class implementing the twin of a removed port.
     *
     * @return class-string
     */
    private function twinClass(string $twin): string
    {
        foreach (VariantKernel::COMPAT_PORTS as [, $portTwin, $class]) {
            if ($portTwin === $twin) {
                return $class;
            }
        }

        self::fail('no test class for ' . $twin);
    }

    /**
     * @return list<string> the user deprecations the callable raised
     */
    private function deprecations(callable $callable): array
    {
        $messages = [];
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $callable();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
