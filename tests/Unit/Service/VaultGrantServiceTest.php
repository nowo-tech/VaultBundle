<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultGrantServiceTest extends TestCase
{
    public function testCountForResourcesDelegatesToRepository(): void
    {
        $repository = $this->createMock(VaultGrantRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('countByResources')
            ->with(VaultResourceType::Item, ['a', 'b'])
            ->willReturn(['a' => 2, 'b' => 0]);

        $service = new VaultGrantService($repository);

        self::assertSame(['a' => 2, 'b' => 0], $service->countForResources(VaultResourceType::Item, ['a', 'b']));
    }

    public function testFindByIdDelegatesToRepository(): void
    {
        $user  = new TestUser('1');
        $grant = new VaultGrant(
            VaultResourceType::Item,
            'item-id',
            GranteeType::User,
            '42',
            VaultPermission::Read,
            $user,
        );

        $repository = $this->createMock(VaultGrantRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with($grant->getId())
            ->willReturn($grant);

        $service = new VaultGrantService($repository);

        self::assertSame($grant, $service->findById($grant->getId()));
    }
}
