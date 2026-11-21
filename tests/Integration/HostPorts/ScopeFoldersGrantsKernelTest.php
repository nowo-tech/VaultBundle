<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use InvalidArgumentException;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultFolderView;
use Nowo\VaultBundle\View\VaultGrantView;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function assert;
use function str_repeat;

/**
 * 2.1.0 (DDD-host checklist B1, B8): scope items and folders, folder lifecycle, sharing and tags through the id-based
 * ports, with the host's scope, team and access ports (id mapping).
 */
#[Group('database')]
final class ScopeFoldersGrantsKernelTest extends KernelTestCase
{
    use HostPortsSupport;

    private const PAYLOAD = ['username' => 'u', 'password' => 'REDACTED-TEST'];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    #[Test]
    public function scopeItemsAndFolders(): void
    {
        $this->bootVariant('private_id_new');
        $alice = $this->ref('alice');
        $dave  = $this->ref('dave');

        $scopeAccess = $this->access()->scopeAccess($alice, 'org-1');
        self::assertTrue($scopeAccess?->create);
        self::assertNull($this->access()->scopeAccess($this->ref('bob'), 'org-1'));

        $folder = $this->folders()->createInScope($alice, 'org-1', 'Shared');
        self::assertSame('scope:org-1', $folder->ownerKey);
        self::assertSame('org-1', $folder->ownerScope);
        $child = $this->folders()->createInScope($alice, 'org-1', 'Child', $folder->id);
        self::assertSame($folder->id, $child->parentId);
        $item = $this->items()->createInScope($alice, 'org-1', VaultItemType::Login, 'Router', self::PAYLOAD, $folder->id, ['net']);
        self::assertSame('org-1', $item->ownerScope);
        self::assertNull($item->ownerUserId);
        self::assertSame(['net'], $item->tagNames);
        $this->items()->createInScope($alice, 'org-1', VaultItemType::SecureNote, 'Wifi', ['notes' => 'x']);

        $scope = VaultItemCriteria::forScope('org-1');
        self::assertSame(2, $this->query()->count($scope));
        self::assertSame(1, $this->query()->count($scope->inFolder($folder->id)));
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forUser($this->id('alice'))), 'scope items are not personal items');

        // dave: Read baseline, no create; the organisation (folder, tags) needs Share on a scope item.
        self::assertSame([$item->id => VaultPermission::Read], $this->access()->itemPermissions($dave, [$item->id]));
        self::assertTrue($this->access()->canAccessItem($dave, $item->id, VaultAccessAction::View));
        self::assertFalse($this->access()->canAccessItem($dave, $item->id, VaultAccessAction::Edit));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultItemView => $this->items()->createInScope($dave, 'org-1', VaultItemType::Login, 'x', self::PAYLOAD));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->createInScope($dave, 'org-1', 'x'));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultItemView => $this->items()->move($dave, $item->id, null));
        self::assertNull($this->items()->move($alice, $item->id, null)->folderId, 'alice administers the scope');
        self::assertSame(['lan'], $this->items()->retag($alice, $item->id, ['lan'])->tagNames);

        // A scope item never moves into a personal folder; a parent of another scope or a trashed one is refused.
        $personal = $this->folders()->create($alice, 'Mine');
        $this->assertThrows(VaultOwnerException::class, fn (): VaultItemView => $this->items()->move($alice, $item->id, $personal->id));
        $this->assertThrows(VaultOwnerException::class, fn (): VaultFolderView => $this->folders()->createInScope($alice, 'org-1', 'x', $personal->id));
        $this->assertThrows(VaultOwnerException::class, fn (): VaultFolderView => $this->folders()->createInScope($alice, 'org-1', 'x', 'missing'));

        // Folder lifecycle (personal).
        $sub = $this->folders()->create($alice, 'Sub', $personal->id);
        self::assertSame($personal->id, $sub->parentId);
        self::assertSame('Renamed', $this->folders()->rename($alice, $personal->id, 'Renamed')->name);
        $this->items()->create($alice, VaultItemType::Login, 'In folder', self::PAYLOAD, $personal->id);
        self::assertTrue($this->access()->canAccessFolder($alice, $personal->id, VaultAccessAction::Delete));
        self::assertSame(1, $this->folders()->moveToTrash($alice, $personal->id), 'its active item left the folder');
        self::assertFalse($this->access()->canAccessFolder($alice, $personal->id, VaultAccessAction::View));
        $this->assertThrows(VaultOwnerException::class, fn (): VaultFolderView => $this->folders()->create($alice, 'x', $personal->id), 'a trashed parent');
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($alice, $personal->id, 'x'), 'a trashed folder');
        self::assertFalse($this->folders()->restore($alice, $personal->id)->isTrashed());
        $this->folders()->purge($alice, $personal->id);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->restore($alice, $personal->id), 'purged');
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($this->ref('bob'), $sub->id, 'x'));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($alice, '', 'x'));
        $this->assertThrows(InvalidArgumentException::class, fn (): VaultFolderView => $this->folders()->create($alice, ' '));
        $this->assertThrows(InvalidArgumentException::class, fn (): VaultFolderView => $this->folders()->rename($alice, $sub->id, str_repeat('n', 256)));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->create(VaultUserRef::ofId('nobody'), 'x'));
    }

    #[Test]
    public function sharingAndTags(): void
    {
        $this->bootVariant('private_id_new');
        $alice = $this->ref('alice');
        $bob   = $this->ref('bob');
        $item  = $this->items()->create($alice, VaultItemType::Login, 'Shared', self::PAYLOAD, null, ['work']);

        $grant = $this->grants()->grant($alice, VaultResourceType::Item, $item->id, GranteeType::User, $this->id('bob'), VaultPermission::Read);
        self::assertSame(VaultGrantResult::Created, $grant->result);
        self::assertSame($this->id('bob'), $grant->granteeId);
        self::assertSame(VaultPermission::Read, $grant->permission);
        self::assertSame($this->id('alice'), $grant->createdById);
        self::assertTrue($grant->isActiveAt($grant->createdAt));
        self::assertSame(VaultGrantResult::Updated, $this->grants()->grant($alice, VaultResourceType::Item, $item->id, GranteeType::User, $this->id('bob'), VaultPermission::Write)->result);
        self::assertTrue($this->access()->canAccessItem($bob, $item->id, VaultAccessAction::Edit));

        $listed = $this->grants()->listForResource($alice, VaultResourceType::Item, $item->id);
        self::assertCount(1, $listed);
        self::assertNull($listed[0]->result);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): array => $this->grants()->listForResource($bob, VaultResourceType::Item, $item->id), 'Write is not Share');
        $this->assertThrows(VaultAccessDeniedException::class, fn (): array => $this->grants()->listForResource($alice, VaultResourceType::Item, 'missing'));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): array => $this->grants()->listForResource($alice, VaultResourceType::Folder, ''));
        $this->assertThrows(VaultGrantRefusedException::class, fn (): VaultGrantView => $this->grants()->grant($bob, VaultResourceType::Item, $item->id, GranteeType::User, $this->id('carol'), VaultPermission::Read));
        $this->assertThrows(VaultGrantRefusedException::class, fn (): bool => $this->grants()->revoke($bob, $grant->id));

        $folder = $this->folders()->create($alice, 'Team');
        $this->grants()->grant($alice, VaultResourceType::Folder, $folder->id, GranteeType::Team, 'team-1', VaultPermission::Read);
        self::assertCount(1, $this->grants()->listForResource($alice, VaultResourceType::Folder, $folder->id));

        self::assertTrue($this->grants()->revoke($alice, $grant->id));
        self::assertFalse($this->grants()->revoke($alice, $grant->id), 'already gone');
        self::assertFalse($this->grants()->revoke($alice, ''));
        self::assertFalse($this->access()->canAccessItem($bob, $item->id, VaultAccessAction::View));

        // Tags: only the actor's own personal tags.
        $tags = $this->container->get(VaultTagRepositoryInterface::class);
        assert($tags instanceof VaultTagRepositoryInterface);
        $work = $tags->findOneByCreatorAndName($this->users['alice'], 'work');
        self::assertNotNull($work);
        $this->assertThrows(VaultAccessDeniedException::class, fn () => $this->tagCommands()->delete($bob, $work->getId()));
        $this->tagCommands()->delete($alice, $work->getId());
        self::assertSame([], $this->query()->findById($item->id)?->tagNames);
        $this->assertThrows(VaultAccessDeniedException::class, fn () => $this->tagCommands()->delete($alice, $work->getId()), 'gone');
    }
}
