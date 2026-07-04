<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultSharedItemResolverTest extends TestCase
{
    public function testReturnsItemsGrantedToViewer(): void
    {
        $viewer = new TestUser('2');
        $owner  = new TestUser('1');
        $item   = new VaultItem(VaultItemType::Login, 'Shared', $owner, 'cipher');

        $grants = $this->createMock(VaultGrantRepositoryInterface::class);
        $grants->method('findGrantedResourceIds')->willReturn([$item->getId()]);

        $items = $this->createMock(VaultItemRepositoryInterface::class);
        $items->expects(self::once())
            ->method('findByIdsForViewer')
            ->with([$item->getId()], $viewer)
            ->willReturn([$item]);

        $resolver = new VaultSharedItemResolver($grants, $items, new NullVaultTeamMembershipResolver());

        self::assertSame([$item], $resolver->resolve($viewer));
    }

    public function testReturnsEmptyWhenNoGrants(): void
    {
        $grants = $this->createMock(VaultGrantRepositoryInterface::class);
        $grants->method('findGrantedResourceIds')->willReturn([]);

        $items = $this->createMock(VaultItemRepositoryInterface::class);
        $items->expects(self::never())->method('findByIdsForViewer');

        $resolver = new VaultSharedItemResolver($grants, $items, new NullVaultTeamMembershipResolver());

        self::assertSame([], $resolver->resolve(new TestUser('9')));
    }
}
