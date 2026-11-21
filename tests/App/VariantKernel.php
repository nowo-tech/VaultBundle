<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use InvalidArgumentException;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataEraserInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalDataExporterInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
use Nowo\VaultBundle\Tests\App\Ports\PolicyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectory;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectoryV119;
use Nowo\VaultBundle\Tests\App\Worker\NoopServicesResetter;
use Nowo\VaultBundle\Tests\Support\Removed\LegacyAccessChecker;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function array_intersect;
use function array_values;
use function count;
use function explode;
use function in_array;
use function substr;
use function ucfirst;

use const PHP_VERSION_ID;

/**
 * Test kernel for the port, private entity manager, XML mapping and worker tests (1.19.0; since 2.0 the private manager
 * and the framework-free ports are the only modes). The environment name selects the variant:
 * `private_{mapping}_{ports}[_worker][_db][_404]`.
 *
 * - `private`: the only persistence since 2.0 (the 1.18 wiring, `legacy`, was removed);
 * - mapping: `relation` (Doctrine user entity, attributes), `relxml` (relation, XML mapping), `id` (id mapping, a plain
 *   PHP security user and its own directory) or `idxml` (id mapping, XML mapping);
 * - ports: `builtin` (role-based checker, no teams, scopes from the host service) or `new` (the host implements the
 *   access, team and scope ports);
 * - `_worker`: the services resetter is replaced by a no-op (FrankenPHP worker mode with the resetter bypassed);
 * - `_db`: the database server of VAULT_TEST_DATABASE_URL instead of a SQLite file;
 * - `_404`: `security.deny_status: 404`;
 * - 2.0 removed paths, each of which must fail the build: `_rmchecker` (`security.access_checker` names a class that
 *   still implements the removed access port), `_rmalias` (the application aliases the removed user locator id),
 *   `_rmaccess` / `_rmlocator` / `_rmscope` (application classes that still implement a removed port, registered by
 *   resource discovery with `#[AsDecorator]` / `#[AsAlias]`) and `_pemfalse` (`database.private_entity_manager: false`).
 */
final class VariantKernel extends BaseKernel
{
    private string $mapping;

    private string $ports;

    private readonly bool $worker;

    private readonly bool $server;

    private readonly int $denyStatus;

    private readonly ?string $removed;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $parts = explode('_', $environment);
        if (count($parts) < 3 || $parts[0] !== 'private' || !in_array($parts[1], ['relation', 'relxml', 'id', 'idxml'], true) || !in_array($parts[2], ['builtin', 'new'], true)) {
            throw new InvalidArgumentException('Unknown variant ' . $environment . '.');
        }
        [, $this->mapping, $this->ports] = $parts;
        $this->worker                    = in_array('worker', $parts, true);
        $this->server                    = in_array('db', $parts, true);
        $this->denyStatus                = in_array('404', $parts, true) ? 404 : 403;
        $removed                         = array_values(array_intersect($parts, ['rmchecker', 'rmalias', 'rmaccess', 'rmlocator', 'rmscope', 'pemfalse']));
        $this->removed                   = $removed[0] ?? null;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new VaultBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        foreach (['framework', 'translation', 'twig'] as $file) {
            $loader->load($this->getProjectDir() . '/config/packages/' . $file . '.yaml');
        }

        $byId    = $this->mapping === 'id' || $this->mapping === 'idxml';
        $xml     = $this->mapping === 'relxml' || $this->mapping === 'idxml';
        $ports   = $this->ports;
        $worker  = $this->worker;
        $deny    = $this->denyStatus;
        $removed = $this->removed;
        $url     = $this->server ? '%env(VAULT_TEST_DATABASE_URL)%' : 'sqlite:///%kernel.cache_dir%/variant.db';
        $loader->load(static function (ContainerBuilder $container) use ($byId, $xml, $ports, $worker, $url, $deny, $removed): void {
            $orm = ['naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware'] + (PHP_VERSION_ID >= 80400 ? ['enable_native_lazy_objects' => true] : []);
            if (!$byId) {
                $orm['mappings'] = ['TestUser' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/Entity', 'prefix' => 'Nowo\\VaultBundle\\Tests\\App\\Entity', 'is_bundle' => false]];
            }
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => $url], 'orm' => $orm]);

            $container->loadFromExtension('security', [
                'password_hashers' => [PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4]],
                'providers'        => $byId
                    ? ['app' => ['id' => PortsUserDirectory::class]]
                    : ['app' => ['entity' => ['class' => User::class, 'property' => 'email']]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'app']],
            ]);

            $vault = [
                'user_class'     => $byId ? DddUser::class : User::class,
                'user_mapping'   => $byId ? 'id' : 'relation',
                'encryption_key' => '%env(VAULT_ENCRYPTION_KEY)%',
                'database'       => ['mapping_type' => $xml ? 'xml' : 'attribute'],
                'security'       => ['deny_status' => $deny, 'allow_unlisted_grantees' => true, 'access_roles' => ['ROLE_USER'], 'create_roles' => ['ROLE_USER'], 'list_roles' => ['ROLE_USER'], 'delete_roles' => ['ROLE_USER']],
                'scopes'         => ['enabled' => true, 'access_resolver' => 'app.vault_ports'],
            ];
            $container->register('app.vault_ports', PolicyHostPorts::class)->setPublic(true);
            if ($ports !== 'builtin') {
                $vault['security']['access_checker'] = 'app.vault_ports';
                $vault['team_membership_resolver']   = 'app.vault_ports';
            }
            match ($removed) {
                'rmchecker' => $vault['security']['access_checker']         = 'app.legacy_checker',
                'pemfalse'  => $vault['database']['private_entity_manager'] = false,
                default     => null,
            };
            $container->loadFromExtension('nowo_vault', $vault);
            if ($removed === 'rmchecker') {
                $container->register('app.legacy_checker', LegacyAccessChecker::class);
            } elseif ($removed === 'rmalias') {
                $container->register('app.host_locator', PortsUserDirectory::class);
                $container->setAlias(RemovedPortsPass::USER_LOCATOR, 'app.host_locator');
            } elseif ($removed !== null && $removed !== 'pemfalse') {
                // Resource discovery of application classes, as config/services.yaml does (autoconfigure reads
                // #[AsAlias] / #[AsDecorator]).
                $directory = ucfirst(substr($removed, 2));
                (new PhpFileLoader($container, new FileLocator(__DIR__)))->registerClasses(
                    (new Definition())->setAutowired(true)->setAutoconfigured(true),
                    'Nowo\\VaultBundle\\Tests\\Support\\Removed\\Discovered\\' . $directory . '\\',
                    __DIR__ . '/../Support/Removed/Discovered/' . $directory . '/*',
                );
            }

            if ($byId) {
                $container->register(PortsUserDirectory::class, PortsUserDirectory::class)->setPublic(true);
                $container->register(PortsUserDirectoryV119::class, PortsUserDirectoryV119::class);
                $container->setAlias(VaultUserDirectoryInterface::class, PortsUserDirectoryV119::class);
            }

            if ($worker) {
                // FrankenPHP worker mode with the services resetter bypassed: nothing tagged kernel.reset runs.
                $container->register('services_resetter', NoopServicesResetter::class)->setPublic(true);
            }

            $container->register(VaultEventRecorderSubscriber::class, VaultEventRecorderSubscriber::class)->setAutoconfigured(true);
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);
            // The 1.19 ports a host calls, reachable by the tests.
            foreach ([VaultPersonalDataEraserInterface::class, VaultPersonalDataExporterInterface::class, VaultHealthReportProviderInterface::class] as $port) {
                $container->setAlias('test.port.' . $port, $port)->setPublic(true);
            }
            if ($ports === 'new') {
                $container->setAlias('test.port.' . VaultAccessPolicyInterface::class, VaultAccessPolicyInterface::class)->setPublic(true);
            }
            if ($byId) {
                $container->setAlias('test.port.' . VaultUserDirectoryInterface::class, VaultUserDirectoryInterface::class)->setPublic(true);
            }
        });
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/variant_' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }
}
