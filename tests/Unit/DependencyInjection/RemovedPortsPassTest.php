<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\RemovedPortsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

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
    public function anAliasOfARemovedPortFailsTheBuild(string $port, string $twin): void
    {
        $container = new ContainerBuilder();
        $container->register('app.service', stdClass::class);
        $container->setAlias($port, 'app.service');

        $this->assertFails($container, $port, $twin, ['app.service']);
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function aDefinitionUnderOrDecoratingARemovedPortFailsTheBuild(string $port, string $twin): void
    {
        $container = new ContainerBuilder();
        $container->register($port, stdClass::class);
        $container->register('app.audit', stdClass::class)->setDecoratedService($port);

        $this->assertFails($container, $port, $twin, [$port, 'app.audit']);
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

        $this->assertFails($container, $port, $twin, ['App\\Vault\\LegacyService']);
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
     * @param list<string> $services
     */
    private function assertFails(ContainerBuilder $container, string $port, string $twin, array $services): void
    {
        try {
            (new RemovedPortsPass())->process($container);
            self::fail('the build must fail');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"' . $port . '" was removed in 2.0 (deprecated since 1.19)', $exception->getMessage());
            foreach ($services as $service) {
                self::assertStringContainsString($service, $exception->getMessage());
            }
            self::assertStringContainsString('Implement "' . $twin . '" instead', $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        }
    }
}
