<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultFolderCommandsInterface;
use Nowo\VaultBundle\Service\VaultGrantCommandsInterface;
use Nowo\VaultBundle\Service\VaultItemCommandsInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Nowo\VaultBundle\Service\VaultTagCommandsInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Tests\Integration\Ports\VariantKernelSupport;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function assert;
use function getenv;
use function is_string;
use function str_starts_with;

/**
 * Boots a variant kernel and hands out the 2.1 host ports and references by id of the seeded users.
 */
trait HostPortsSupport
{
    use VariantKernelSupport;

    /** @var array<string, UserInterface> */
    private array $users = [];

    private ContainerInterface $container;

    private function bootVariant(string $variant): void
    {
        // `make test-with-db` (group database): the same tests on MySQL, MariaDB or PostgreSQL.
        $server = getenv('VAULT_TEST_DATABASE_URL');
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => $variant . (is_string($server) && $server !== '' ? '_db' : ''), 'debug' => false]);
        $this->container = self::getContainer();
        self::resetSchema($this->container);
        $this->users = self::seedUsers($this->container, str_starts_with($variant, 'private_id'));
    }

    /**
     * A reference by id only (the vault looks it up again through its user directory).
     */
    private function ref(string $name): VaultUserRef
    {
        return VaultUserRef::ofId($this->id($name), $name . '@example.com', ['ROLE_USER']);
    }

    private function id(string $name): string
    {
        return (string) UserIdResolver::getId($this->users[$name]);
    }

    private function items(): VaultItemCommandsInterface
    {
        $port = $this->container->get('test.port.' . VaultItemCommandsInterface::class);
        assert($port instanceof VaultItemCommandsInterface);

        return $port;
    }

    private function folders(): VaultFolderCommandsInterface
    {
        $port = $this->container->get('test.port.' . VaultFolderCommandsInterface::class);
        assert($port instanceof VaultFolderCommandsInterface);

        return $port;
    }

    private function grants(): VaultGrantCommandsInterface
    {
        $port = $this->container->get('test.port.' . VaultGrantCommandsInterface::class);
        assert($port instanceof VaultGrantCommandsInterface);

        return $port;
    }

    private function tagCommands(): VaultTagCommandsInterface
    {
        $port = $this->container->get('test.port.' . VaultTagCommandsInterface::class);
        assert($port instanceof VaultTagCommandsInterface);

        return $port;
    }

    private function query(): VaultItemQueryInterface
    {
        $port = $this->container->get('test.port.' . VaultItemQueryInterface::class);
        assert($port instanceof VaultItemQueryInterface);

        return $port;
    }

    private function access(): VaultAccessCheckInterface
    {
        $port = $this->container->get('test.port.' . VaultAccessCheckInterface::class);
        assert($port instanceof VaultAccessCheckInterface);

        return $port;
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    private function assertThrows(string $class, callable $call, string $case = ''): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            self::assertInstanceOf($class, $exception, $case . ': ' . $exception::class . ' ' . $exception->getMessage());

            return;
        }

        self::fail('Expected ' . $class . ($case !== '' ? ' (' . $case . ')' : ''));
    }
}
