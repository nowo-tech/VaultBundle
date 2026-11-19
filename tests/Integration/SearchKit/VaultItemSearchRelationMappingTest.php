<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use LogicException;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewers;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function array_map;
use function class_exists;
use function dirname;

if (!class_exists(EntitySearchProviderTestCase::class)) {
    return;
}

/**
 * `user_mapping: relation` (the default: `creator_id` is a foreign key to the host users table): the SearchKit contract
 * and a shared item, with Doctrine user entities as owners and viewers.
 */
#[Group('searchkit')]
#[CoversClass(VaultItemSearchDefinition::class)]
#[CoversClass(VaultSearchViewers::class)]
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
