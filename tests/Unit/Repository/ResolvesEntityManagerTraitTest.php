<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultTagRepository;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResolvesEntityManagerTraitTest extends TestCase
{
    public function testClosedManagerIsResetOnNextRequestWithoutKernelReset(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturnOnConsecutiveCalls(true, true, false, false);
        $closed->expects(self::once())->method('flush')->willThrowException(new RuntimeException('unique violation'));

        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('isOpen')->willReturn(true);
        $fresh->expects(self::once())->method('persist');
        $fresh->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('vault')->willReturnOnConsecutiveCalls($closed, $closed, $closed, $fresh);
        $registry->expects(self::once())->method('resetManager')->with('vault')->willReturn($fresh);

        $repository = new DoctrineOrmVaultTagRepository($closed, $registry, 'vault');
        $tag        = new VaultTag('work', new TestUser('1'));

        // Request 1: flush fails and Doctrine closes the manager.
        try {
            $repository->save($tag);
            self::fail('Expected flush failure.');
        } catch (RuntimeException $e) {
            self::assertSame('unique violation', $e->getMessage());
        }

        // Request 2 on the same repository instance: the closed manager is replaced.
        $repository->save($tag);
    }

    public function testFallsBackToInjectedManagerWithoutRegistry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        (new DoctrineOrmVaultTagRepository($em))->save(new VaultTag('work', new TestUser('1')));
    }
}
