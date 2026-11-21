<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Dto;

use InvalidArgumentException;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(VaultItemCriteria::class)]
final class VaultItemCriteriaTest extends TestCase
{
    public function testOwnersAndDefaults(): void
    {
        $user = VaultItemCriteria::forUser('42');
        self::assertSame('42', $user->ownerUserId);
        self::assertNull($user->ownerScope);
        self::assertSame('user:42', $user->ownerKey());
        self::assertSame(VaultTrashFilter::Active, $user->trash);
        self::assertSame(VaultItemSort::UpdatedDesc, $user->sort);
        self::assertSame(0, $user->offset);
        self::assertSame(VaultItemCriteria::DEFAULT_LIMIT, $user->limit);

        $scope = VaultItemCriteria::forScope('org-1');
        self::assertSame('scope:org-1', $scope->ownerKey());
        self::assertSame('scope:org-1', VaultItemCriteria::forOwnerKey('scope:org-1')->ownerKey());
        self::assertSame(' 42', VaultItemCriteria::forOwnerKey('user: 42')->ownerUserId, 'ids are never trimmed');
    }

    public function testWithersReplaceOneValueAndKeepTheRest(): void
    {
        $criteria = VaultItemCriteria::forUser('1')
            ->withType(VaultItemType::Login)
            ->inFolder('f')
            ->withTag('t')
            ->withTitle('Exact')
            ->withTitleContaining('xa')
            ->withTrash(VaultTrashFilter::Any)
            ->sortedBy(VaultItemSort::TitleAsc)
            ->page(10, 5);

        self::assertSame(VaultItemType::Login, $criteria->type);
        self::assertSame('f', $criteria->folderId);
        self::assertFalse($criteria->withoutFolder);
        self::assertSame('t', $criteria->tagId);
        self::assertSame('Exact', $criteria->title);
        self::assertSame('xa', $criteria->titleContains);
        self::assertSame(VaultTrashFilter::Any, $criteria->trash);
        self::assertSame(VaultItemSort::TitleAsc, $criteria->sort);
        self::assertSame([10, 5], [$criteria->offset, $criteria->limit]);

        $cleared = $criteria->withoutFolder()->withType(null)->withTag('')->withTitle(null)->withTitleContaining(null);
        self::assertNull($cleared->folderId);
        self::assertTrue($cleared->withoutFolder);
        self::assertNull($cleared->type);
        self::assertNull($cleared->tagId);
        self::assertNull($cleared->title);
        self::assertNull($cleared->titleContains);
        self::assertFalse($cleared->inFolder('g')->withoutFolder);
        self::assertNull($cleared->inFolder(null)->folderId);
        self::assertSame('Exact', $criteria->title, 'the original is unchanged');
    }

    /**
     * @return iterable<string, array{0: callable(): VaultItemCriteria}>
     */
    public static function invalid(): iterable
    {
        yield 'blank user id' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser(' ')];
        yield 'long user id' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser(str_repeat('a', 129))];
        yield 'bad scope' => [static fn (): VaultItemCriteria => VaultItemCriteria::forScope('Org 1')];
        yield 'bad owner key' => [static fn (): VaultItemCriteria => VaultItemCriteria::forOwnerKey('team:1')];
        yield 'negative offset' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser('1')->page(-1)];
        yield 'zero limit' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser('1')->page(0, 0)];
        yield 'huge limit' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser('1')->page(0, VaultItemCriteria::MAX_LIMIT + 1)];
        yield 'blank title filter' => [static fn (): VaultItemCriteria => VaultItemCriteria::forUser('1')->withTitleContaining('  ')];
    }

    /**
     * @param callable(): VaultItemCriteria $build
     */
    #[DataProvider('invalid')]
    public function testInvalidCriteriaAreRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }
}
