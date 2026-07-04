<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Entity;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultEntityTest extends TestCase
{
    public function testVaultItemLifecycle(): void
    {
        $user   = new TestUser('1');
        $folder = new VaultFolder('Work', $user);
        $item   = new VaultItem(VaultItemType::Login, 'GitHub', $user, 'cipher', $folder);

        self::assertSame(VaultItemType::Login, $item->getItemType());
        self::assertSame('cipher', $item->getCiphertext());
        self::assertSame($user, $item->getCreator());
        self::assertSame($folder, $item->getFolder());
        self::assertFalse($item->isDeleted());

        $item->setTitle('GitLab')->setCiphertext('new-cipher')->setFolder(null);
        self::assertSame('GitLab', $item->getTitle());
        self::assertNull($item->getFolder());

        $item->markDeleted();
        self::assertTrue($item->isDeleted());
        self::assertNotNull($item->getDeletedAt());

        $item->restore();
        self::assertFalse($item->isDeleted());
        self::assertNotNull($item->getCreatedAt());
        self::assertNotNull($item->getUpdatedAt());
    }

    public function testVaultFolderLifecycle(): void
    {
        $user   = new TestUser('2');
        $parent = new VaultFolder('Parent', $user);
        $folder = new VaultFolder('Child', $user, $parent);

        self::assertSame('Child', $folder->getName());
        self::assertSame($parent, $folder->getParent());
        self::assertSame($user, $folder->getCreator());
        self::assertNotNull($folder->getCreatedAt());
        self::assertNotNull($folder->getUpdatedAt());

        $folder->setName('Renamed')->setParent(null);
        self::assertSame('Renamed', $folder->getName());
        self::assertNull($folder->getParent());

        self::assertFalse($folder->isDeleted());
        self::assertNull($folder->getDeletedAt());

        $folder->markDeleted();
        self::assertTrue($folder->isDeleted());
        $folder->restore();
        self::assertFalse($folder->isDeleted());
    }

    public function testVaultGrant(): void
    {
        $user  = new TestUser('3');
        $grant = new VaultGrant(
            VaultResourceType::Item,
            'item-id',
            GranteeType::User,
            '42',
            VaultPermission::Write,
            $user,
        );

        self::assertSame(VaultResourceType::Item, $grant->getResourceType());
        self::assertSame('item-id', $grant->getResourceId());
        self::assertSame(GranteeType::User, $grant->getGranteeType());
        self::assertSame('42', $grant->getGranteeId());
        self::assertSame(VaultPermission::Write, $grant->getPermission());
        self::assertNotEmpty($grant->getId());
        self::assertNotNull($grant->getCreatedAt());

        $grant->setPermission(VaultPermission::Admin);
        self::assertSame(VaultPermission::Admin, $grant->getPermission());
        self::assertSame($user, $grant->getCreatedBy());
        self::assertNotNull($grant->getCreatedAt());
    }
}
