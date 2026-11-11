<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use PHPUnit\Framework\TestCase;

/**
 * 1.17.0: id accessors next to the user accessors, with a related user object (relation mapping) and with a stored id
 * (id mapping; the entities then return a {@see VaultUserId}).
 */
final class VaultEntityUserIdsTest extends TestCase
{
    public function testIdAccessorsWithRelatedUsers(): void
    {
        $alice = new TestUser('alice');

        $item = new VaultItem(VaultItemType::Login, 'Bank', $alice, 'cipher');
        self::assertSame('alice', $item->getCreatorId());
        self::assertSame('alice', $item->getCreatedById());
        self::assertSame($alice, $item->getCreator());

        $folder = new VaultFolder('Work', $alice);
        self::assertSame('alice', $folder->getCreatorId());
        self::assertSame('alice', $folder->getCreatedById());

        $scopeFolder = VaultFolder::forScope('Ops', 'org-1', null);
        self::assertNull($scopeFolder->getCreatorId());
        self::assertNull($scopeFolder->getCreatedById());

        self::assertSame('alice', (new VaultTag('prod', $alice))->getCreatorId());
        self::assertNull(VaultTag::forScope('prod', 'org-1')->getCreatorId());
        self::assertSame('alice', (new VaultGrant(VaultResourceType::Item, 'i', GranteeType::User, 'bob', VaultPermission::Read, $alice))->getCreatedById());
        self::assertNull((new VaultGrant(VaultResourceType::Item, 'i', GranteeType::User, 'bob', VaultPermission::Read, null))->getCreatedById());
        self::assertSame('alice', (new VaultExtensionToken('hash', new DateTimeImmutable('+1 hour'), $alice))->getUserId());
    }

    public function testStoredIdsAreExposedAsVaultUserIds(): void
    {
        $item = new VaultItem(VaultItemType::Login, 'Bank', 'u-7', 'cipher');
        self::assertEquals(new VaultUserId('u-7'), $item->getCreator());
        self::assertEquals(new VaultUserId('u-7'), $item->getCreatedBy());
        self::assertSame('user:u-7', $item->getOwner()->key());
        self::assertTrue($item->getOwner()->isUser(new TestUser('u-7')));

        $folder = new VaultFolder('Work', 'u-7');
        self::assertEquals(new VaultUserId('u-7'), $folder->getCreator());
        self::assertEquals(new VaultUserId('u-7'), $folder->getCreatedBy());
        self::assertSame('user:u-7', $folder->getOwner()->key());

        $tag = new VaultTag('prod', 'u-7');
        self::assertEquals(new VaultUserId('u-7'), $tag->getCreator());
        self::assertSame('user:u-7', $tag->getOwner()->key());

        self::assertEquals(new VaultUserId('u-7'), (new VaultGrant(VaultResourceType::Item, 'i', GranteeType::User, 'bob', VaultPermission::Read, 'u-7'))->getCreatedBy());
        $token = new VaultExtensionToken('hash', new DateTimeImmutable('+1 hour'), 'u-7');
        self::assertEquals(new VaultUserId('u-7'), $token->getUser());
        self::assertSame('u-7', $token->getUserId());
    }
}
