<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use LogicException;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 2.0, audit VT200-M1: an application that kept the 1.x wiring of a removed port id (an alias of the id, the documented
 * 1.17 way to replace the user lookup) with a class that implements the framework-free twin keeps working in a real
 * kernel: the twin is aliased to that service, with a 2.x deprecation (removed in 3.0). An alias of the twin to another
 * service fails the build (keep only one). Audit VT200-M2: a service registered under a removed id fails the build:
 * 1.21 never used it (its own alias of the id replaced it and the built-in served the port), so adopting it would
 * change access.
 */
final class RemovedPortCompatKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compatible(): iterable
    {
        foreach (VariantKernel::COMPAT_PORTS as $port => $wiring) {
            yield $port . ': alias of the removed id' => ['alias', $port];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ports(): iterable
    {
        foreach (VariantKernel::COMPAT_PORTS as $port => $wiring) {
            yield $port => [$port];
        }
    }

    #[Test]
    #[DataProvider('compatible')]
    public function theTwinIsAliasedToTheServiceWithADeprecation(string $mode, string $port): void
    {
        [$id, $twin, $class] = VariantKernel::COMPAT_PORTS[$port];
        $kernel              = $this->kernel(sprintf('private_relation_builtin_compat%s%s', $mode, $port));
        $deprecations        = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $kernel->boot();
        } finally {
            restore_error_handler();
        }

        try {
            self::assertInstanceOf($class, $kernel->getContainer()->get('test.compat'), sprintf('%s is asked through %s', $class, $twin));
            $found = false;
            foreach ($deprecations as $message) {
                $found = $found || (str_contains($message, sprintf('The service id "%s" was removed in 2.0', $id)) && str_contains($message, sprintf('Alias "%s" instead (the old id is accepted until 3.0).', $twin)));
            }
            self::assertTrue($found, 'the 2.x deprecation names the removed id and the twin');
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    #[DataProvider('ports')]
    public function aServiceRegisteredUnderTheRemovedIdFailsTheBuild(string $port): void
    {
        [$id, $twin] = VariantKernel::COMPAT_PORTS[$port];
        $kernel      = $this->kernel('private_relation_builtin_compatdef' . $port);

        try {
            $kernel->boot();
            self::fail('a service 1.21 did not use must not start serving the port');
        } catch (LogicException $exception) {
            self::assertStringContainsString(sprintf('"%s" was removed in 2.0 (deprecated since 1.19)', $id), $exception->getMessage());
            self::assertStringContainsString(sprintf('a service is registered under it, which 1.21 did not use (the built-in served the port): alias "%s" to it to use it, or remove it', $twin), $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    #[DataProvider('ports')]
    public function anAliasOfTheTwinToAnotherServiceFailsTheBuild(string $port): void
    {
        [$id, $twin] = VariantKernel::COMPAT_PORTS[$port];
        $kernel      = $this->kernel('private_relation_builtin_compatconflict' . $port);

        try {
            $kernel->boot();
            self::fail('two services behind one port must fail the build');
        } catch (LogicException $exception) {
            self::assertStringContainsString(sprintf('"%s" was removed in 2.0 (deprecated since 1.19)', $id), $exception->getMessage());
            self::assertStringContainsString(sprintf('while "%s" points at "app.compat_other": keep only the alias of "%s"', $twin, $twin), $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * A kernel without debug (its build deprecations reach the test's handler) and without a cached container.
     */
    private function kernel(string $environment): VariantKernel
    {
        $kernel = new VariantKernel($environment, false);
        (new Filesystem())->remove($kernel->getCacheDir());

        return $kernel;
    }
}
