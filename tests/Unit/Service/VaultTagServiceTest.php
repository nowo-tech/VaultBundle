<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function str_repeat;

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

    public function testDeleteForCreatorRejectsUnknownTag(): void
    {
        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $service->deleteForCreator(new TestUser('1'), 'missing');
    }

    public function testListForCreatorDelegatesToRepository(): void
    {
        $user = new TestUser('1');
        $tags = [new VaultTag('a', $user), new VaultTag('b', $user)];
        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->expects(self::once())->method('findByCreator')->with($user)->willReturn($tags);

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));

        self::assertSame($tags, $service->listForCreator($user));
    }

    public function testFindOrCreateReturnsExistingTag(): void
    {
        $user     = new TestUser('1');
        $existing = new VaultTag('work', $user);
        $repo     = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->expects(self::once())->method('findOneByCreatorAndName')->with($user, 'work')->willReturn($existing);
        $repo->expects(self::never())->method('save');

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));

        self::assertSame($existing, $service->findOrCreate($user, "  work \n"));
    }

    public function testFindOrCreateCreatesAndSavesNewTag(): void
    {
        $user = new TestUser('1');
        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->method('findOneByCreatorAndName')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::isInstanceOf(VaultTag::class));

        $service = new VaultTagService($repo, $this->createMock(VaultItemRepositoryInterface::class));
        $tag     = $service->findOrCreate($user, 'my   new  tag');

        self::assertSame('my new tag', $tag->getName());
        self::assertSame($user, $tag->getCreator());
    }

    public function testFindOrCreateRejectsBlankName(): void
    {
        $service = new VaultTagService(
            $this->createMock(VaultTagRepositoryInterface::class),
            $this->createMock(VaultItemRepositoryInterface::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->findOrCreate(new TestUser('1'), "  \t ");
    }

    public function testSyncItemTagsSkipsBlankAndDuplicateNamesAndSavesItem(): void
    {
        $user = new TestUser('1');
        $work = new VaultTag('work', $user);
        $home = new VaultTag('home', $user);
        $item = new VaultItem(VaultItemType::SecureNote, 'note', $user, 'ciphertext');

        $repo = $this->createMock(VaultTagRepositoryInterface::class);
        $repo->method('findOneByCreatorAndName')->willReturnCallback(
            static fn (object $creator, string $name): VaultTag => $name === 'work' ? $work : $home,
        );
        $itemRepo = $this->createMock(VaultItemRepositoryInterface::class);
        $itemRepo->expects(self::once())->method('save')->with($item);

        $service = new VaultTagService($repo, $itemRepo);
        $service->syncItemTags($item, $user, ['work', '  ', 'home', ' work ']);

        self::assertSame(['work', 'home'], $item->getTagNames());
    }

    public function testNormalizeNameCollapsesWhitespaceAndTruncates(): void
    {
        self::assertSame('', VaultTagService::normalizeName("  \n "));
        self::assertSame('a b', VaultTagService::normalizeName(" a \t b "));
        self::assertSame(str_repeat('x', VaultTagService::MAX_NAME_LENGTH), VaultTagService::normalizeName(str_repeat('x', 100)));
    }
}
