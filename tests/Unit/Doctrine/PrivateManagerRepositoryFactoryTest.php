<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Tests\Support\RetainingRepositoryFactory;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function dirname;
use function extension_loaded;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Audit NX: the private manager owns its repository factory. A factory shared with the application (DoctrineBundle's
 * ContainerRepositoryFactory) keeps one repository, and so one manager, per manager instance for the life of the
 * process: in FrankenPHP worker mode (kernel reset false) every replaced private manager would leak.
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
        self::assertInstanceOf(DefaultRepositoryFactory::class, $live->getConfiguration()->getRepositoryFactory());
        self::assertNotSame($source->getConfiguration(), $live->getConfiguration(), 'A copy: the source configuration is never modified.');
        self::assertSame($source->getConfiguration()->getMetadataDriverImpl(), $live->getConfiguration()->getMetadataDriverImpl());
        $own->getRepository(VaultItem::class);
        self::assertSame(0, $shared->managers(), 'The shared factory never saw the private manager.');
    }

    public function testReplacedManagersAreNotKeptAliveAndMemoryDoesNotGrow(): void
    {
        $shared = new RetainingRepositoryFactory();
        $source = $this->source($shared);
        $own    = new VaultEntityManager($this->registry($source));

        $refs     = [];
        $baseline = 0;
        for ($i = 0; $i < self::REPLACEMENTS; ++$i) {
            $live = $own->live();
            $own->getRepository(VaultItem::class);
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
        $growth = memory_get_usage() - $baseline;

        self::assertSame(0, $shared->managers(), 'No manager was registered in the shared factory.');
        self::assertLessThanOrEqual(1, $alive, sprintf('%d replaced managers are still referenced.', $alive));
        self::assertLessThan(self::MARGIN, $growth, sprintf('Memory grew by %d bytes over %d replaced managers.', $growth, self::REPLACEMENTS - 20));
        self::assertSame($shared, $source->getConfiguration()->getRepositoryFactory());
    }

    private function registry(EntityManagerInterface $source): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($source);
        $registry->method('getManagerForClass')->willReturn($source);
        $registry->method('getConnection')->willReturn($source->getConnection());

        return $registry;
    }

    private function source(RepositoryFactory $factory): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-nxrf-proxies');
            $config->setProxyNamespace('NowoNxrfProxies');
        }
        $config->setRepositoryFactory($factory);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    }
}
