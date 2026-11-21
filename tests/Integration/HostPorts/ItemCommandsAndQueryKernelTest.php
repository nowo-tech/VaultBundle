<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use InvalidArgumentException;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultItemChangedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_fill;
use function array_keys;
use function array_map;
use function assert;
use function str_repeat;
use function strtoupper;

/**
 * 2.1.0 (DDD-host checklist B1, B7, B8): a host drives personal items through the id-based ports only (a user
 * reference by id, item and folder ids, views back), in both user mappings.
 */
#[Group('database')]
final class ItemCommandsAndQueryKernelTest extends KernelTestCase
{
    use HostPortsSupport;

    private const PAYLOAD = ['username' => 'u', 'password' => 'REDACTED-TEST', 'websites' => ['https://example.com']];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function variants(): iterable
    {
        yield 'relation mapping' => ['private_relation_new'];
        yield 'id mapping, XML' => ['private_idxml_new'];
    }

    #[Test]
    #[DataProvider('variants')]
    public function aHostWritesAndReadsPersonalItemsByIdOnly(string $variant): void
    {
        $this->bootVariant($variant);
        $alice = $this->ref('alice');

        $folder = $this->folders()->create($alice, 'Work');
        self::assertSame('user:' . $this->id('alice'), $folder->ownerKey);
        self::assertSame($this->id('alice'), $folder->ownerUserId);
        self::assertNull($folder->ownerScope);
        self::assertFalse($folder->isTrashed());

        $item = $this->items()->create($alice, VaultItemType::Login, 'Mail', self::PAYLOAD, $folder->id, ['zeta', 'alpha']);
        self::assertSame(['alpha', 'zeta'], $item->tagNames);
        self::assertSame($folder->id, $item->folderId);
        self::assertSame(VaultItemType::Login, $item->type);
        self::assertSame($this->id('alice'), $item->ownerUserId);
        self::assertSame($this->id('alice'), $item->createdById);
        self::assertFalse($item->isTrashed());
        $other = $this->items()->create($alice, VaultItemType::SecureNote, 'Note', ['notes' => 'x']);
        self::assertSame([], $other->tagNames);
        self::assertNull($other->folderId);

        // Optimistic save with the edit token; a stale token is refused and nothing is written.
        $updated = $this->items()->update($alice, $item->id, 'Mail 2', self::PAYLOAD, $item->editToken);
        self::assertSame('Mail 2', $updated->title);
        $this->assertThrows(VaultItemChangedException::class, fn (): VaultItemView => $this->items()->update($alice, $item->id, 'Lost', self::PAYLOAD, $item->editToken));
        self::assertSame('Mail 2', $this->query()->findById($item->id)?->title);
        self::assertSame('Mail 3', $this->items()->update($alice, $item->id, 'Mail 3', self::PAYLOAD)->title);

        self::assertNull($this->items()->move($alice, $item->id, null)->folderId);
        self::assertNull($this->items()->move($alice, $item->id, null)->folderId, 'same folder: nothing to do');
        self::assertSame($folder->id, $this->items()->move($alice, $item->id, $folder->id)->folderId);
        self::assertSame(['beta'], $this->items()->retag($alice, $item->id, ['beta', ' beta '])->tagNames);

        // Query port.
        $mine = VaultItemCriteria::forUser($this->id('alice'));
        self::assertSame(2, $this->query()->count($mine));
        $page = $this->query()->find($mine->sortedBy(VaultItemSort::TitleAsc));
        self::assertSame(['Mail 3', 'Note'], $this->titles($page->items));
        self::assertSame(2, $page->total);
        self::assertFalse($page->hasMore());
        // Both rows may share a creation second: the id breaks the tie, so the pages never overlap.
        $first  = $this->query()->find($mine->sortedBy(VaultItemSort::CreatedAsc)->page(0, 1));
        $second = $this->query()->find($mine->sortedBy(VaultItemSort::CreatedAsc)->page(1, 1));
        self::assertCount(1, $first->items);
        self::assertTrue($first->hasMore());
        self::assertFalse($second->hasMore());
        self::assertNotSame($first->items[0]->id, $second->items[0]->id);
        self::assertCount(1, $this->query()->find($mine->sortedBy(VaultItemSort::CreatedDesc)->page(0, 1))->items);
        self::assertCount(2, $this->query()->find($mine)->items, 'default order: last change first');
        self::assertSame(['Mail 3'], $this->titles($this->query()->find($mine->inFolder($folder->id))->items));
        self::assertSame(['Note'], $this->titles($this->query()->find($mine->withoutFolder())->items));
        self::assertSame(['Note'], $this->titles($this->query()->find($mine->withType(VaultItemType::SecureNote))->items));
        self::assertSame(['Mail 3'], $this->titles($this->query()->find($mine->withTitleContaining('AIL'))->items));
        $tags = $this->container->get(VaultTagRepositoryInterface::class);
        assert($tags instanceof VaultTagRepositoryInterface);
        $beta = $tags->findOneByCreatorAndName($this->users['alice'], 'beta');
        self::assertNotNull($beta);
        self::assertSame(['Mail 3'], $this->titles($this->query()->find($mine->withTag($beta->getId()))->items));
        self::assertSame(1, $this->query()->count($mine->withTitle('Mail 3')));
        self::assertSame(['Mail 3'], $this->titles($this->query()->find($mine->withTitle('Mail 3'))->items));
        self::assertSame(0, $this->query()->count($mine->withTitle('mail 3')), 'exact title, byte for byte');
        self::assertSame([], $this->query()->find($mine->withTitle('mail 3'))->items);
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forUser($this->id('bob'))));
        // Byte-exact owner ids (audit YF-L4): a padded or non-canonical variant of alice's id matches nothing, also in
        // the totals, and an id that cannot be an integer key is no database error.
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forUser('0' . $this->id('alice'))));
        self::assertSame(0, $this->query()->find(VaultItemCriteria::forUser('0' . $this->id('alice')))->total);
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forUser('not-a-number')));
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forUser(strtoupper($this->id('alice')) . 'X')));
        self::assertSame(0, $this->query()->count(VaultItemCriteria::forOwnerKey($item->ownerKey)->withTrash(VaultTrashFilter::Trashed)));

        // Access by id.
        self::assertTrue($this->access()->canAccessItem($alice, $item->id, VaultAccessAction::Edit));
        self::assertFalse($this->access()->canAccessItem($this->ref('dave'), $item->id, VaultAccessAction::View));
        self::assertFalse($this->access()->canAccessItem($alice, 'missing', VaultAccessAction::View));
        self::assertFalse($this->access()->canAccessItem(VaultUserRef::ofId('nobody'), $item->id, VaultAccessAction::View));
        self::assertFalse($this->access()->isItemReadOnly($alice, $item->id));
        self::assertFalse($this->access()->isItemReadOnly($alice, ''));
        self::assertTrue($this->access()->canAccessFolder($alice, $folder->id, VaultAccessAction::Edit));
        self::assertFalse($this->access()->canAccessFolder($alice, '', VaultAccessAction::Edit));
        self::assertSame([$item->id => VaultPermission::Admin, 'missing' => null], $this->access()->itemPermissions($alice, [$item->id, $item->id, 'missing']), 'unknown and denied answer alike');
        self::assertSame([$item->id => null], $this->access()->itemPermissions($this->ref('dave'), [$item->id]));
        self::assertSame([], $this->access()->itemPermissions($alice, []));
        self::assertSame([$item->id => null], $this->access()->itemPermissions(VaultUserRef::ofId('nobody'), [$item->id]));
        self::assertNull($this->access()->scopeAccess(VaultUserRef::ofId('nobody'), 'org-1'));

        // Trash, restore, purge.
        self::assertTrue($this->items()->moveToTrash($alice, $item->id)->isTrashed());
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultItemView => $this->items()->update($alice, $item->id, 'x', self::PAYLOAD), 'a trashed item is not edited');
        self::assertFalse($this->access()->canAccessItem($alice, $item->id, VaultAccessAction::View), 'a trashed item is only reachable to restore or purge');
        self::assertTrue($this->access()->canAccessItem($alice, $item->id, VaultAccessAction::Restore));
        self::assertSame(['Note'], $this->titles($this->query()->find($mine)->items));
        self::assertSame(['Mail 3'], $this->titles($this->query()->find($mine->withTrash(VaultTrashFilter::Trashed))->items));
        self::assertSame(2, $this->query()->count($mine->withTrash(VaultTrashFilter::Any)));
        self::assertTrue($this->query()->findById($item->id)?->isTrashed());
        self::assertFalse($this->items()->restore($alice, $item->id)->isTrashed());
        $this->items()->purge($alice, $item->id);
        self::assertNull($this->query()->findById($item->id));
        self::assertSame([$other->id], array_map(static fn (VaultItemView $view): string => $view->id, $this->query()->findByIds(['missing', $other->id, $other->id])));
        self::assertSame([], $this->query()->findByIds([]));
    }

    #[Test]
    public function refusalsSayNothingAboutWhatExists(): void
    {
        $this->bootVariant('private_idxml_new');
        $alice = $this->ref('alice');
        $bob   = $this->ref('bob');
        $item  = $this->items()->create($alice, VaultItemType::Login, 'Mine', self::PAYLOAD);
        $bobs  = $this->folders()->create($bob, 'Bob folder');

        $denied = [
            'unknown actor'          => fn (): VaultItemView => $this->items()->create(VaultUserRef::ofId('nobody'), VaultItemType::Login, 'x', self::PAYLOAD),
            'unknown item'           => fn (): VaultItemView => $this->items()->update($alice, 'missing', 'x', self::PAYLOAD),
            'empty id'               => fn (): VaultItemView => $this->items()->moveToTrash($alice, ''),
            'no access'              => fn (): VaultItemView => $this->items()->update($bob, $item->id, 'x', self::PAYLOAD),
            'not the organiser'      => fn (): VaultItemView => $this->items()->retag($bob, $item->id, ['x']),
            'trash without access'   => fn (): VaultItemView => $this->items()->moveToTrash($bob, $item->id),
            'restore without access' => fn (): VaultItemView => $this->items()->restore($bob, $item->id),
            'purge without access'   => fn () => $this->items()->purge($bob, $item->id),
            'scope non-member'       => fn (): VaultItemView => $this->items()->createInScope($bob, 'org-1', VaultItemType::Login, 'x', self::PAYLOAD),
        ];
        foreach ($denied as $case => $call) {
            $this->assertThrows(VaultAccessDeniedException::class, $call, $case);
        }

        $this->assertThrows(VaultOwnerException::class, fn (): VaultItemView => $this->items()->create($alice, VaultItemType::Login, 'x', self::PAYLOAD, $bobs->id), 'folder of another user');
        $this->assertThrows(VaultOwnerException::class, fn (): VaultItemView => $this->items()->create($alice, VaultItemType::Login, 'x', self::PAYLOAD, 'missing'), 'unknown folder');
        $this->assertThrows(VaultOwnerException::class, fn (): VaultItemView => $this->items()->move($alice, $item->id, $bobs->id), 'move into a folder of another user');
        $this->assertThrows(VaultOwnerException::class, fn (): VaultItemView => $this->items()->createInScope($alice, 'org-1', VaultItemType::Login, 'x', self::PAYLOAD, 'missing'), 'unknown scope folder');
        $this->assertThrows(InvalidArgumentException::class, fn (): VaultItemView => $this->items()->create($alice, VaultItemType::Login, '  ', self::PAYLOAD), 'blank title');
        $this->assertThrows(InvalidArgumentException::class, fn (): VaultItemView => $this->items()->create($alice, VaultItemType::Login, str_repeat('x', 256), self::PAYLOAD), 'long title');
        $this->assertThrows(InvalidArgumentException::class, fn (): array => $this->query()->findByIds(array_fill(0, 501, 'x')), 'too many ids');
        self::assertSame(1, $this->query()->count(VaultItemCriteria::forUser($this->id('alice'))), 'no refused call wrote anything');
    }

    /**
     * @param list<VaultItemView> $items
     *
     * @return list<string>
     */
    private function titles(array $items): array
    {
        return array_map(static fn (VaultItemView $view): string => $view->title, $items);
    }
}
