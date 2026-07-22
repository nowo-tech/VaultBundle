<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultTagServiceTest extends TestCase
{
    public function testDeleteForCreatorRemovesOwnedTag(): void
    {
        $user = new TestUser('1');
        $tag  = new VaultTag('work', $user);

        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->method('findById')->with($tag->getId())->willReturn($tag);
        $repo->expects(self::once())->method('remove')->with($tag);

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));
        $service->deleteForCreator($user, $tag->getId());
    }

    public function testDeleteForCreatorRejectsForeignTag(): void
    {
        $owner = new TestUser('1');
        $other = new TestUser('2');
        $tag   = new VaultTag('work', $owner);

        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->method('findById')->with($tag->getId())->willReturn($tag);
        $repo->expects(self::never())->method('remove');

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $service->deleteForCreator($other, $tag->getId());
    }
}
