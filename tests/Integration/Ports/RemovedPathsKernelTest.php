<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Nowo\VaultBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * 2.0 (REQ-ARCH-001, "a removed host-facing path fails loudly"): every 1.x wiring of a removed port, and the removed
 * `database.private_entity_manager: false`, fails the container build of a real kernel with a message that names what
 * to do, instead of being ignored. Covers application classes registered by resource discovery (`#[AsDecorator]`,
 * `#[AsAlias]`) that still implement a removed interface: Symfony keeps their definitions with only a loading error and
 * would drop them silently (a dropped access decorator would widen access).
 */
final class RemovedPathsKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function removedPaths(): iterable
    {
        yield 'access_checker on a class that still implements the removed port' => ['private_relation_builtin_rmchecker', ['"app.legacy_checker"', 'cannot be loaded', 'Nowo\\VaultBundle\\Security\\VaultAccessCheckerInterface', VaultAccessPolicyInterface::class]];
        yield 'application alias of the removed user locator id' => ['private_id_builtin_rmalias', [RemovedPortsPass::USER_LOCATOR, '"app.host_locator"', VaultUserDirectoryInterface::class]];
        yield 'discovered #[AsDecorator] of the removed access port' => ['private_relation_builtin_rmaccess', ['RestrictingAccessDecorator', 'Nowo\\VaultBundle\\Security\\VaultAccessCheckerInterface', VaultAccessPolicyInterface::class]];
        yield 'discovered #[AsAlias] of the removed user locator port' => ['private_relation_builtin_rmlocator', ['HostUserLocator', RemovedPortsPass::USER_LOCATOR, VaultUserDirectoryInterface::class]];
        yield 'discovered #[AsAlias] of a class implementing both scope ports' => ['private_relation_new_rmscope', ['HostScopes', 'Nowo\\VaultBundle\\Security\\VaultScopeAccessResolverInterface', VaultScopeAccessProviderInterface::class]];
        yield 'private_entity_manager: false' => ['private_relation_builtin_pemfalse', ['private_entity_manager: false was removed in 2.0', 'UPGRADING.md "To 2.0.0"']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('removedPaths')]
    public function theBuildFailsWithAMessageThatSaysWhatToDo(string $environment, array $expected): void
    {
        $kernel = new VariantKernel($environment, true);

        try {
            $kernel->boot();
            self::fail($environment . ' must fail the container build');
        } catch (Throwable $exception) {
            foreach ($expected as $part) {
                self::assertStringContainsString($part, $exception->getMessage(), $environment);
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
