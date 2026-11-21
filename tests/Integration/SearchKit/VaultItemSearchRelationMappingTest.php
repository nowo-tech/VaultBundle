<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use LogicException;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\Attributes\Group;

use function array_map;
use function dirname;

/**
 * `user_mapping: relation` (the default: `creator_id` is a foreign key to the host users table): the SearchKit contract
 * and a shared item, with Doctrine user entities as owners and viewers.
 */
#[Group('searchkit')]
final class VaultItemSearchRelationMappingTest extends VaultItemSearchTestCase
{
    /** @var array<string, int> identifier => user id */
    private static array $userIds = [];

    protected static function userMapping(): string
    {
        return VaultUserMapping::RELATION;
    }

    protected static function userClass(): array
    {
        return [User::class, [dirname(__DIR__, 2) . '/App/Entity']];
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        self::$userIds = [];
    }

    protected function tenants(): array
    {
        return [(string) $this->userId(self::VIEWER_A), (string) $this->userId(self::VIEWER_B)];
    }

    protected function userOf(int|string $id, string $identifier): User
    {
        $user = self::em()->find(User::class, (int) $id);
        if (!$user instanceof User || $user->getUserIdentifier() !== $identifier) {
            throw new LogicException('Unknown test user.');
        }

        return $user;
    }

    protected function ownerValue(int|string $id): User
    {
        $user = self::em()->find(User::class, (int) $id);
        if (!$user instanceof User) {
            throw new LogicException('Unknown test user.');
        }

        return $user;
    }

    public function testAnItemSharedWithTheViewerAppearsWithoutItsFolder(): void
    {
        [$alice, $bob] = $this->tenants();
        $marker        = $this->marker();
        $folder        = $this->persistFolder(new VaultFolder('Bob folder', $this->ownerValue($bob)));
        $shared        = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' shared', $this->ownerValue($bob), 'ciphertext', $folder));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' private', $this->ownerValue($bob), 'ciphertext', $folder));
        $own = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' own', $this->ownerValue($alice), 'ciphertext', $this->persistFolder(new VaultFolder('Mine', $this->ownerValue($alice)))));
        $this->grant(VaultResourceType::Item, $shared, GranteeType::User, $alice);
        $this->actAs($alice, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        $rows = [];
        foreach ($group->items as $row) {
            $rows[$row->url] = $row->subtitle;
        }
        self::assertSame(['/tools/vault/items/' . $own => 'login · Mine', '/tools/vault/items/' . $shared => 'login'], $rows);
        self::assertSame([$marker . ' own', $marker . ' shared'], array_map(static fn (SearchItem $row): string => $row->title, $group->items));
    }

    /**
     * Audit VJ-1 (b), relation mapping (`IDENTITY(f.creator) = IDENTITY(i.creator)`): a folder grant covers only the
     * items with the folder's owner, so another user's items moved into a shared folder are not even SQL candidates.
     */
    public function testAFolderGrantCoversOnlyTheFolderOwnersItems(): void
    {
        [$alice, $bob] = $this->tenants();
        $carol         = (string) $this->userId('searchkit-contract-carol');
        $marker        = $this->marker();
        $folder        = $this->persistFolder(new VaultFolder('Bob shared', $this->ownerValue($bob)));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' bob', $this->ownerValue($bob), 'ciphertext', $folder));
        for ($row = 0; $row < 4; ++$row) {
            $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' carol ' . $row, $this->ownerValue($carol), 'ciphertext', $folder));
        }
        $this->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, $alice);
        $this->actAs($alice, self::VIEWER_A);

        $group = (new EntitySearchProvider($this->searchDefinition(maxCandidates: 2)))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame([$marker . ' bob'], array_map(static fn (SearchItem $row): string => $row->title, $group->items));
        self::assertSame(1, $group->total);
        self::assertTrue($group->exactTotal);
    }

    /**
     * Audit VJ2-1: with the relation mapping, grants to look-alikes of the viewer's team (`Ops`, `Öps`, `ops `) are not
     * SQL candidates either: grantee_id has the binary NO PAD collation on MySQL / MariaDB in both mappings.
     */
    public function testTeamLookAlikeGrantsNeverFillTheWindow(): void
    {
        [$alice, $bob] = $this->tenants();
        $marker        = $this->marker();
        for ($row = 0; $row < 3; ++$row) {
            $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' own ' . $row, $this->ownerValue($alice), 'ciphertext'));
        }
        foreach (['Ops', "\u{00D6}ps", 'ops ', 'OPS', 'oPs', "\u{00F6}ps"] as $row => $lookAlike) {
            $shared = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' team ' . $row, $this->ownerValue($bob), 'ciphertext'));
            $this->grant(VaultResourceType::Item, $shared, GranteeType::Team, $lookAlike);
        }
        $this->teamIds[$alice] = ['ops'];
        $this->actAs($alice, self::VIEWER_A);

        $group = (new EntitySearchProvider($this->searchDefinition(maxCandidates: 5)))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(3, $group->items);
        self::assertSame(3, $group->total);
        self::assertTrue($group->exactTotal);
    }

    private function userId(string $identifier): int
    {
        if (!isset(self::$userIds[$identifier])) {
            $user = (new User())->setEmail($identifier)->setPassword('x');
            self::em()->persist($user);
            self::em()->flush();
            self::$userIds[$identifier] = (int) $user->getId();
        }

        return self::$userIds[$identifier];
    }
}
