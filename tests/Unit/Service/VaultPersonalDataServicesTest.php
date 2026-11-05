<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Closure;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.16.0 personal-data services without an ORM entity manager, and the erasure result.
 */
final class VaultPersonalDataServicesTest extends TestCase
{
    public function testErasureResultCounts(): void
    {
        $result = new VaultErasureResult(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);
        self::assertSame(55, $result->total());
        self::assertSame(['grants_received', 'grants_on_personal', 'grants_created_anonymised', 'item_tags', 'items', 'folders', 'tags', 'scope_items_anonymised', 'scope_folders_anonymised', 'extension_tokens'], array_keys($result->toArray()));
        self::assertSame(0, (new VaultErasureResult())->total());
    }

    /**
     * @return iterable<string, array{Closure(ManagerRegistry): mixed}>
     */
    public static function services(): iterable
    {
        yield 'exporter' => [static fn (ManagerRegistry $registry): mixed => (new VaultPersonalDataExporter($registry))->export('1')];
        yield 'eraser' => [static fn (ManagerRegistry $registry): mixed => (new VaultPersonalDataEraser($registry, new VaultGrantService(new InMemoryGrantRepository()), new InMemoryGrantRepository()))->erase('1')];
        yield 'streamer' => [static fn (ManagerRegistry $registry): mixed => (new VaultPersonalExportStreamer($registry, self::cryptographer()))->measure(new stdClass())];
    }

    /**
     * @param Closure(ManagerRegistry): mixed $call
     */
    #[DataProvider('services')]
    public function testTheServicesNeedAnOrmManager(Closure $call): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $call($registry);
    }

    private static function cryptographer(): VaultPayloadCryptographerInterface
    {
        return new class implements VaultPayloadCryptographerInterface {
            public function encrypt(array $payload): string
            {
                return '';
            }

            public function decrypt(string $ciphertext): array
            {
                return [];
            }
        };
    }
}
