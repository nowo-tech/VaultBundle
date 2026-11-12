<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ContainerRepositoryFactory;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Doctrine\PrivateManagerRepositoryFactory;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Tests\Support\RepoFactory\FilterA;
use Nowo\VaultBundle\Tests\Support\RepoFactory\FilterB;
use Nowo\VaultBundle\Tests\Support\RepoFactory\HostThing;
use Nowo\VaultBundle\Tests\Support\RepoFactory\HostThingRepository;
use Nowo\VaultBundle\Tests\Support\RetainingRepositoryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use WeakReference;

use function dirname;
use function extension_loaded;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Audit NX / NZ-M1: the private manager owns its repository factory. DoctrineBundle's shared ContainerRepositoryFactory
 * keeps one repository, and so one manager, per manager instance for the life of the process: in FrankenPHP worker
 * mode (kernel reset false) every replaced private manager would leak. Service repositories are the exception: they
 * come from the source factory (the container service), as host listeners on the shared event manager expect.
 */
final class PrivateManagerRepositoryFactoryTest extends TestCase
{
    private const REPLACEMENTS = 150;
    private const MARGIN       = 1_500_000;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    public function testThePrivateManagerDoesNotUseTheSharedRepositoryFactory(): void
    {
        $shared = new RetainingRepositoryFactory();
        $source = $this->source($shared);
        $own    = new VaultEntityManager($this->registry($source));

        $live = $own->live();

        self::assertSame($shared, $source->getConfiguration()->getRepositoryFactory(), 'The application keeps its factory.');
        self::assertNotSame($shared, $live->getConfiguration()->getRepositoryFactory());
        self::assertInstanceOf(PrivateManagerRepositoryFactory::class, $live->getConfiguration()->getRepositoryFactory());
        self::assertNotSame($source->getConfiguration(), $live->getConfiguration(), 'A copy: the source configuration is never modified.');
        self::assertSame($source->getConfiguration()->getMetadataDriverImpl(), $live->getConfiguration()->getMetadataDriverImpl());
        $repository = $live->getRepository(VaultItem::class);
        self::assertSame($repository, $live->getRepository(VaultItem::class), 'Kept by the manager\'s own factory.');
        self::assertSame(0, $shared->managers(), 'The shared factory never saw the private manager.');
    }

    public function testReplacedManagersAreNotKeptAliveAndMemoryDoesNotGrow(): void
    {
        $shared = new RetainingRepositoryFactory();
        $source = $this->source($shared);
        $own    = new VaultEntityManager($this->registry($source));

        [$alive, $growth] = $this->replace($own);

        self::assertSame(0, $shared->managers(), 'No manager was registered in the shared factory.');
        self::assertLessThanOrEqual(1, $alive, sprintf('%d replaced managers are still referenced.', $alive));
        self::assertLessThan(self::MARGIN, $growth, sprintf('Memory grew by %d bytes over %d replaced managers.', $growth, self::REPLACEMENTS - 20));
        self::assertSame($shared, $source->getConfiguration()->getRepositoryFactory());
    }

    public function testReplacedManagersAreNotKeptAliveByTheRealContainerRepositoryFactory(): void
    {
        [$source] = $this->hostSource();
        $own      = new VaultEntityManager($this->registry($source));

        [$alive, $growth] = $this->replace($own);

        self::assertInstanceOf(ContainerRepositoryFactory::class, $source->getConfiguration()->getRepositoryFactory());
        self::assertLessThanOrEqual(1, $alive, sprintf('%d replaced managers are still referenced.', $alive));
        self::assertLessThan(self::MARGIN, $growth, sprintf('Memory grew by %d bytes over %d replaced managers.', $growth, self::REPLACEMENTS - 20));
    }

    public function testAServiceRepositoryIsTheContainerServiceOfTheSourceFactory(): void
    {
        [$source, $service] = $this->hostSource();
        $own                = new VaultEntityManager($this->registry($source));

        self::assertSame($service, $own->live()->getRepository(HostThing::class));
        self::assertSame($service, $own->live()->getRepository(HostThing::class), 'Not keyed by manager, never retained by the private factory.');
        $own->live()->close();
        self::assertSame($service, $own->live()->getRepository(HostThing::class), 'The same service after the manager was replaced.');
    }

    public function testAHostListenerThatLoadsAServiceRepositoryDoesNotBreakTheBundleFlush(): void
    {
        [$source, $service, $loaded] = $this->hostSource();
        $own                         = new VaultEntityManager($this->registry($source));

        $own->live()->flush();

        self::assertSame([$service], $loaded->repositories, 'The listener on the shared event manager reached the private manager and got the service.');
        self::assertSame($source->getEventManager(), $own->live()->getEventManager());
    }

    public function testAFilterRegisteredOnTheSourceAfterTheManagerWasCreatedIsMirrored(): void
    {
        $source = $this->source(new RetainingRepositoryFactory());
        $own    = new VaultEntityManager($this->registry($source));
        $own->live();

        $source->getConfiguration()->addFilter('late', FilterA::class);
        $source->getFilters()->enable('late');

        self::assertInstanceOf(FilterA::class, $own->live()->getFilters()->getFilter('late'));
    }

    public function testAFilterRegisteredAgainWithAnotherClassIsPickedUp(): void
    {
        $source = $this->source(new RetainingRepositoryFactory());
        $own    = new VaultEntityManager($this->registry($source));

        $source->getConfiguration()->addFilter('swap', FilterA::class);
        $source->getFilters()->enable('swap');
        $before = $own->live()->getFilters()->getFilter('swap');
        self::assertInstanceOf(FilterA::class, $before);

        $source->getConfiguration()->addFilter('swap', FilterB::class);

        $after = $own->live()->getFilters()->getFilter('swap');
        self::assertInstanceOf(FilterB::class, $after, 'The instance of the previous class was dropped.');
    }

    /**
     * @return array{int, int} replaced managers still referenced, memory growth after the warm-up
     */
    private function replace(VaultEntityManager $own): array
    {
        $refs     = [];
        $baseline = 0;
        for ($i = 0; $i < self::REPLACEMENTS; ++$i) {
            $live = $own->live();
            $live->getRepository(VaultItem::class);
            $refs[] = WeakReference::create($live);
            $live->close();
            unset($live);
            if ($i === 19) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        $own->live()->close();
        gc_collect_cycles();

        $alive = 0;
        foreach ($refs as $ref) {
            if ($ref->get() !== null) {
                ++$alive;
            }
        }

        return [$alive, memory_get_usage() - $baseline];
    }

    /**
     * The application's manager as DoctrineBundle builds it: ContainerRepositoryFactory over a container that holds
     * the service repository of a host entity, and a host listener on the shared event manager that loads it.
     *
     * @return array{EntityManagerInterface, HostThingRepository, object{repositories: list<object>}}
     */
    private function hostSource(): array
    {
        $loaded = new class {
            /** @var list<object> */
            public array $repositories = [];

            public function onFlush(OnFlushEventArgs $args): void
            {
                $this->repositories[] = $args->getObjectManager()->getRepository(HostThing::class);
            }
        };
        $events = new EventManager();
        $events->addEventListener(Events::onFlush, $loaded);

        $holder = new class {
            public ?HostThingRepository $repository = null;
        };
        $locator            = new ServiceLocator([HostThingRepository::class => static fn (): HostThingRepository => $holder->repository ?? throw new LogicException('Not built yet.')]);
        $source             = $this->source(new ContainerRepositoryFactory($locator), $events);
        $holder->repository = new HostThingRepository($this->registry($source));

        return [$source, $holder->repository, $loaded];
    }

    private function registry(EntityManagerInterface $source): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($source);
        $registry->method('getManagerForClass')->willReturn($source);
        $registry->method('getConnection')->willReturn($source->getConnection());

        return $registry;
    }

    private function source(RepositoryFactory $factory, ?EventManager $events = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity', dirname(__DIR__, 2) . '/Support/RepoFactory'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-nxrf-proxies');
            $config->setProxyNamespace('NowoNxrfProxies');
        }
        $config->setRepositoryFactory($factory);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
    }
}
