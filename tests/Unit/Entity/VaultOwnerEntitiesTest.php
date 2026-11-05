<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 1.16.0 owner model of items, folders and tags, and grant expiry.
 */
final class VaultOwnerEntitiesTest extends TestCase
{
    public function testPersonalItemKeepsItsCreatorAndAuditColumns(): void
    {
        $alice = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Mail', $alice, 'c');

        self::assertTrue($item->isPersonal());
        self::assertNull($item->getOwnerScope());
        self::assertSame('user:1', $item->getOwner()->key());
        self::assertSame($alice, $item->getCreator());
        self::assertSame($alice, $item->getCreatedBy());
        self::assertEquals($item->getCreatedAt(), $item->getSecretChangedAt());
        self::assertFalse($item->isCatalogueHidden());
    }

    public function testScopeItem(): void
    {
        $bob    = new TestUser('2');
        $folder = VaultFolder::forScope('Ops', 'org-1', $bob);
        $item   = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', $bob, 'c', $folder);

        self::assertFalse($item->isPersonal());
        self::assertSame('org-1', $item->getOwnerScope());
        self::assertSame('scope:org-1', $item->getOwner()->key());
        self::assertSame($bob, $item->getCreatedBy());
        self::assertSame($folder, $item->getFolder());
        $item->setCatalogueHidden(true);
        self::assertTrue($item->isCatalogueHidden());

        $this->expectException(VaultOwnerException::class);
        $item->getCreator();
    }

    public function testItemNeedsExactlyOneOwner(): void
    {
        $this->expectException(VaultOwnerException::class);
        new VaultItem(VaultItemType::Login, 'Both', new TestUser('1'), 'c', null, 'org-1');
    }

    public function testInvalidScopeKeyIsRefused(): void
    {
        $this->expectException(VaultOwnerException::class);
        VaultItem::forScope(VaultItemType::Login, 'x', '../org', null, 'c');
    }

    public function testRotationLeavesTimestampsAlone(): void
    {
        $item    = new VaultItem(VaultItemType::Login, 'Mail', new TestUser('1'), 'c');
        $updated = $item->getUpdatedAt();
        $secret  = $item->getSecretChangedAt();

        $item->replaceCiphertextForRotation('c2');
        self::assertSame('c2', $item->getCiphertext());
        self::assertSame($updated, $item->getUpdatedAt());
        self::assertSame($secret, $item->getSecretChangedAt());

        $at = new DateTimeImmutable('2030-01-01 00:00:00');
        $item->markSecretChanged($at);
        self::assertSame($at, $item->getSecretChangedAt());
    }

    public function testFolderOwners(): void
    {
        $alice    = new TestUser('1');
        $personal = new VaultFolder('Mine', $alice);
        $scope    = VaultFolder::forScope('Shared', 'org-1', null);

        self::assertSame('user:1', $personal->getOwner()->key());
        self::assertSame($alice, $personal->getCreator());
        self::assertSame($alice, $personal->getCreatedBy());
        self::assertNull($personal->getOwnerScope());
        self::assertSame('org-1', $scope->getOwnerScope());
        self::assertNull($scope->getCreatedBy());

        $this->expectException(VaultOwnerException::class);
        $scope->getCreator();
    }

    public function testTagOwners(): void
    {
        $alice = new TestUser('1');
        $tag   = new VaultTag('work', $alice);
        $scope = VaultTag::forScope('prod', 'org-1');

        self::assertSame($alice, $tag->getCreator());
        self::assertSame('user:1', $tag->getOwner()->key());
        self::assertNull($tag->getOwnerScope());
        self::assertSame('org-1', $scope->getOwnerScope());
        self::assertSame('scope:org-1', $scope->getOwner()->key());

        $this->expectException(VaultOwnerException::class);
        $scope->getCreator();
    }

    public function testGrantExpiryIsStrict(): void
    {
        $expiresAt = new DateTimeImmutable('2026-11-06 14:00:00');
        $grant     = new VaultGrant(VaultResourceType::Item, 'i', GranteeType::User, '2', VaultPermission::Read, new TestUser('1'), $expiresAt);

        self::assertTrue($grant->isTemporary());
        self::assertSame($expiresAt, $grant->getExpiresAt());
        self::assertTrue($grant->isActiveAt($expiresAt->modify('-1 second')));
        self::assertFalse($grant->isActiveAt($expiresAt));
        self::assertFalse($grant->isActiveAt($expiresAt->modify('+1 second')));

        $grant->setExpiresAt(null);
        self::assertFalse($grant->isTemporary());
        self::assertTrue($grant->isActiveAt($expiresAt->modify('+10 years')));
    }
}
