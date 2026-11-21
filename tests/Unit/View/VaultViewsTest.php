<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\View;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\View\VaultGrantView;
use Nowo\VaultBundle\View\VaultItemPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VaultGrantView::class)]
#[CoversClass(VaultItemPage::class)]
final class VaultViewsTest extends TestCase
{
    public function testATemporaryGrantIsActiveUntilItsExpiry(): void
    {
        $now   = new DateTimeImmutable('2026-01-01 10:00:00');
        $grant = new VaultGrantView('g', VaultResourceType::Item, 'i', GranteeType::User, 'u', VaultPermission::Read, $now, null, $now);

        self::assertTrue($grant->isActiveAt($now->modify('-1 second')));
        self::assertFalse($grant->isActiveAt($now));
        self::assertNull($grant->result);
    }

    public function testAPageKnowsWhetherAnotherFollows(): void
    {
        self::assertTrue((new VaultItemPage([], 11, 0, 10))->hasMore());
        self::assertFalse((new VaultItemPage([], 10, 0, 10))->hasMore());
    }
}
