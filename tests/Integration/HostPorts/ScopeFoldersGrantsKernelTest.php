<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultGrantCommands;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultFolderView;
use Nowo\VaultBundle\View\VaultGrantView;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_filter;
use function array_values;
use function assert;
use function str_repeat;
use function strtoupper;

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
    public function organisingIsTheOwners(): void
    {
        $this->bootVariant('private_id_new');
        $alice = $this->ref('alice');
        $erin  = $this->ref('erin');

        // A Write grantee of a personal folder may not rename it (audit YF-I6).
        $mine = $this->folders()->create($alice, 'Mine');
        $this->grants()->grant($alice, VaultResourceType::Folder, $mine->id, GranteeType::User, $this->id('bob'), VaultPermission::Write);
        self::assertTrue($this->access()->canAccessFolder($this->ref('bob'), $mine->id, VaultAccessAction::Edit));
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($this->ref('bob'), $mine->id, 'Taken'));

        // Scope folders: renamed by a member who may Share them (alice: Admin baseline), not by a Read member.
        $f1 = $this->folders()->createInScope($alice, 'org-1', 'F1');
        $f2 = $this->folders()->createInScope($alice, 'org-1', 'F2');
        self::assertSame('F1 renamed', $this->folders()->rename($alice, $f1->id, 'F1 renamed')->name);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($this->ref('dave'), $f1->id, 'x'));

        // erin (grants only) administers one item in F1: she may move it out, but never into F2, a folder she cannot use
        // (audit YF-L3: a move must not extend F2's grants to the item).
        $item = $this->items()->createInScope($alice, 'org-1', VaultItemType::Login, 'Router', self::PAYLOAD, $f1->id);
        $this->grants()->grant($alice, VaultResourceType::Item, $item->id, GranteeType::User, $this->id('erin'), VaultPermission::Admin);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultItemView => $this->items()->move($erin, $item->id, $f2->id));
        self::assertSame($f1->id, $this->query()->findById($item->id)?->folderId);
        self::assertNull($this->items()->move($erin, $item->id, null)->folderId);
        self::assertSame($f2->id, $this->items()->move($alice, $item->id, $f2->id)->folderId, 'a member with a baseline uses every folder');
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->createInScope($this->ref('bob'), 'org-1', 'x', 'missing'), 'the scope gate before the parent');
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
        foreach (['already gone' => $grant->id, 'empty id' => ''] as $case => $grantId) {
            $this->assertRefused(fn (): bool => $this->grants()->revoke($alice, $grantId), $case);
        }

        // Exact resource id (audit YF-L2): a case variant (the same row for a case-insensitive MySQL / MariaDB
        // collation) is refused like a resource the actor may not share, and nothing is stored.
        $this->assertRefused(fn (): VaultGrantView => $this->grants()->grant($alice, VaultResourceType::Item, strtoupper($item->id), GranteeType::User, $this->id('bob'), VaultPermission::Read), 'case variant');
        $this->assertRefused(fn (): VaultGrantView => $this->grants()->grant($alice, VaultResourceType::Item, 'missing', GranteeType::User, $this->id('bob'), VaultPermission::Read), 'unknown');
        self::assertSame([], $this->grants()->listForResource($alice, VaultResourceType::Item, $item->id));
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

    /**
     * Audit YF-D4: the refusals the grant commands decide themselves (an inexact resource id, an unknown grant) write
     * the same notice as every refusal of the grant service, ids only.
     */
    #[Test]
    public function refusalsOfTheCommandsAreLogged(): void
    {
        $this->bootVariant('private_id_new');
        $logger = new RecordingLogger();
        $alice  = $this->ref('alice');
        $item   = $this->items()->create($alice, VaultItemType::Login, 'Shared', self::PAYLOAD);
        $grants = $this->grantCommandsLoggingTo($logger);

        $this->assertRefused(fn (): VaultGrantView => $grants->grant($alice, VaultResourceType::Item, strtoupper($item->id), GranteeType::User, $this->id('bob'), VaultPermission::Read), 'case variant');
        $this->assertRefused(static fn (): bool => $grants->revoke($alice, 'missing-grant'), 'unknown grant');
        $this->assertRefused(static fn (): bool => $grants->revoke($alice, ''), 'empty grant id');
        $this->assertRefused(fn (): VaultGrantView => $grants->grant($this->ref('bob'), VaultResourceType::Item, $item->id, GranteeType::User, $this->id('carol'), VaultPermission::Read), 'not shareable');

        $notice = static fn (string $actor, array $context): array => ['level' => 'notice', 'message' => 'Vault grant refused.', 'context' => ['bundle' => 'nowo_vault', 'action' => 'grant_refused', 'code' => VaultGrantRefusedException::ACTOR_CANNOT_SHARE, 'actor_id' => $actor] + $context];
        self::assertSame([
            $notice($this->id('alice'), ['resource_type' => 'item', 'resource_id' => strtoupper($item->id)]),
            $notice($this->id('alice'), ['resource_type' => null, 'resource_id' => null, 'grant_id' => 'missing-grant']),
            $notice($this->id('alice'), ['resource_type' => null, 'resource_id' => null, 'grant_id' => '']),
            $notice($this->id('bob'), ['resource_type' => 'item', 'resource_id' => $item->id]),
        ], array_values(array_filter($logger->records, static fn (array $record): bool => $record['message'] === 'Vault grant refused.')));
    }

    /**
     * The grant commands of the container, on a grant service that logs to the given logger.
     */
    private function grantCommandsLoggingTo(RecordingLogger $logger): VaultGrantCommands
    {
        $get = function (string $id): object {
            $service = $this->container->get($id);
            assert($service !== null);

            return $service;
        };
        $repository = $get(VaultGrantRepositoryInterface::class);
        $guard      = $get(VaultAccessGuard::class);
        $itemRepo   = $get(VaultItemRepositoryInterface::class);
        $folderRepo = $get(VaultFolderRepositoryInterface::class);
        $refs       = $get(VaultUserRefResolver::class);
        $references = $get(VaultUserReferenceResolverInterface::class);
        $manager    = $get(VaultEntityManager::class);
        $unit       = $get(VaultWriteUnit::class);
        assert($repository instanceof VaultGrantRepositoryInterface && $guard instanceof VaultAccessGuard && $itemRepo instanceof VaultItemRepositoryInterface && $folderRepo instanceof VaultFolderRepositoryInterface);
        assert($refs instanceof VaultUserRefResolver && $references instanceof VaultUserReferenceResolverInterface && $manager instanceof VaultEntityManager && $unit instanceof VaultWriteUnit);

        return new VaultGrantCommands(
            new VaultGrantService($repository, $logger, accessGuard: $guard, itemRepository: $itemRepo, folderRepository: $folderRepo),
            $guard,
            $refs,
            $references,
            $manager,
            $unit,
        );
    }

    private function assertRefused(callable $call, string $case): void
    {
        try {
            $call();
        } catch (VaultGrantRefusedException $exception) {
            self::assertSame(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, $exception->getReason(), $case);

            return;
        }

        self::fail('Expected a refusal: ' . $case);
    }
}
