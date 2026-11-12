<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use InvalidArgumentException;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
use Nowo\VaultBundle\Tests\App\Ports\BothHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\LegacyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PassThroughLegacyDecorators;
use Nowo\VaultBundle\Tests\App\Ports\PolicyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectory;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectoryV119;
use Nowo\VaultBundle\Tests\App\Worker\NoopServicesResetter;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function count;
use function explode;
use function in_array;

use const PHP_VERSION_ID;

/**
 * 1.19.0 test kernel for the port, private entity manager, XML mapping and worker tests. The environment name selects
 * the variant: `{persistence}_{mapping}_{ports}[_worker][_db]`.
 *
 * - persistence: `legacy` (`database.private_entity_manager: false`, the 1.18 wiring) or `private`;
 * - mapping: `relation` (Doctrine user entity, attributes), `relxml` (relation, XML mapping), `id` (id mapping, a plain
 *   PHP security user and its own directory) or `idxml` (id mapping, XML mapping);
 * - ports: `builtin` (role-based checker, no teams, scopes from the deprecated host service), `legacy` (the host
 *   implements only the deprecated port interfaces), `new` (only the 1.19 ones), `both` (a class implementing both),
 *   `decorator` (both, plus host decorators of the three deprecated access ports);
 * - `_worker`: the services resetter is replaced by a no-op (FrankenPHP worker mode with the resetter bypassed);
 * - `_db`: the database server of VAULT_TEST_DATABASE_URL instead of a SQLite file;
 * - `_404`: `security.deny_status: 404`.
 */
final class VariantKernel extends BaseKernel
{
    private string $persistence;

    private string $mapping;

    private string $ports;

    private bool $worker;

    private bool $server;

    private int $denyStatus;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $parts = explode('_', $environment);
        if (count($parts) < 3 || !in_array($parts[0], ['legacy', 'private'], true) || !in_array($parts[1], ['relation', 'relxml', 'id', 'idxml'], true) || !in_array($parts[2], ['builtin', 'legacy', 'new', 'both', 'decorator'], true)) {
            throw new InvalidArgumentException('Unknown variant ' . $environment . '.');
        }
        [$this->persistence, $this->mapping, $this->ports] = $parts;
        $this->worker                                      = in_array('worker', $parts, true);
        $this->server                                      = in_array('db', $parts, true);
        $this->denyStatus                                  = in_array('404', $parts, true) ? 404 : 403;
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

        $byId     = $this->mapping === 'id' || $this->mapping === 'idxml';
        $xml      = $this->mapping === 'relxml' || $this->mapping === 'idxml';
        $private  = $this->persistence === 'private';
        $ports    = $this->ports;
        $worker   = $this->worker;
        $deny     = $this->denyStatus;
        $url      = $this->server ? '%env(VAULT_TEST_DATABASE_URL)%' : 'sqlite:///%kernel.cache_dir%/variant.db';
        $loader->load(static function (ContainerBuilder $container) use ($byId, $xml, $private, $ports, $worker, $url, $deny): void {
            $orm = ['naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware'] + (PHP_VERSION_ID >= 80400 ? ['enable_native_lazy_objects' => true] : []);
            if (!$byId) {
                $orm['mappings'] = ['TestUser' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/Entity', 'prefix' => 'Nowo\\VaultBundle\\Tests\\App\\Entity', 'is_bundle' => false]];
            }
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => $url], 'orm' => $orm]);

            $container->loadFromExtension('security', [
                'password_hashers' => ['Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface' => ['algorithm' => 'auto', 'cost' => 4]],
                'providers'        => $byId
                    ? ['app' => ['id' => PortsUserDirectory::class]]
                    : ['app' => ['entity' => ['class' => User::class, 'property' => 'email']]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'app']],
            ]);

            $vault = [
                'user_class'     => $byId ? DddUser::class : User::class,
                'user_mapping'   => $byId ? 'id' : 'relation',
                'encryption_key' => '%env(VAULT_ENCRYPTION_KEY)%',
                'database'       => ['private_entity_manager' => $private, 'mapping_type' => $xml ? 'xml' : 'attribute'],
                'security'       => ['deny_status' => $deny, 'allow_unlisted_grantees' => true, 'access_roles' => ['ROLE_USER'], 'create_roles' => ['ROLE_USER'], 'list_roles' => ['ROLE_USER'], 'delete_roles' => ['ROLE_USER']],
                'scopes'         => ['enabled' => true, 'access_resolver' => 'app.vault_ports'],
            ];
            $hostClass = match ($ports) {
                'builtin', 'legacy' => LegacyHostPorts::class,
                'new'               => PolicyHostPorts::class,
                default             => BothHostPorts::class,
            };
            $container->register('app.vault_ports', $hostClass)->setPublic(true);
            if ($ports !== 'builtin') {
                $vault['security']['access_checker'] = 'app.vault_ports';
                $vault['team_membership_resolver']   = 'app.vault_ports';
            }
            $container->loadFromExtension('nowo_vault', $vault);

            if ($ports === 'decorator') {
                foreach (['access' => VaultAccessCheckerInterface::class, 'teams' => VaultTeamMembershipResolverInterface::class, 'scopes' => VaultScopeAccessResolverInterface::class] as $name => $port) {
                    $container->register('app.vault_audit.' . $name, PassThroughLegacyDecorators::class)
                        ->setDecoratedService($port)
                        ->setArguments([new Reference('app.vault_audit.' . $name . '.inner')])
                        ->setPublic(true);
                }
            }

            if ($byId) {
                $container->register(PortsUserDirectory::class, PortsUserDirectory::class)->setPublic(true);
                if (in_array($ports, ['new', 'both', 'decorator'], true)) {
                    $container->register(PortsUserDirectoryV119::class, PortsUserDirectoryV119::class);
                    $container->setAlias(VaultUserDirectoryInterface::class, PortsUserDirectoryV119::class);
                } else {
                    $container->setAlias(VaultUserLocatorInterface::class, PortsUserDirectory::class);
                }
            }

            if ($worker) {
                // FrankenPHP worker mode with the services resetter bypassed: nothing tagged kernel.reset runs.
                $container->register('services_resetter', NoopServicesResetter::class)->setPublic(true);
            }

            $container->register(VaultEventRecorderSubscriber::class, VaultEventRecorderSubscriber::class)->setAutoconfigured(true);
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);
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
