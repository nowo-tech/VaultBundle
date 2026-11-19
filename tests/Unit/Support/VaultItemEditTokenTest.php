<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Support\VaultItemEditToken;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VaultItemEditToken::class)]
final class VaultItemEditTokenTest extends TestCase
{
    public function testTheTokenIsStableAndChangesWithEveryEditableValue(): void
    {
        $user = new TestUser('1');
        $item = new VaultItem(VaultItemType::Login, 'Title', $user, 'cipher-1');
        $base = VaultItemEditToken::of($item);

        self::assertSame($base, VaultItemEditToken::of($item));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $base);

        $tokens = [$base];
        $item->setCiphertext('cipher-2');
        $tokens[] = VaultItemEditToken::of($item);
        $item->setTitle('Other');
        $tokens[] = VaultItemEditToken::of($item);
        $item->setFolder(new VaultFolder('Docs', $user));
        $tokens[] = VaultItemEditToken::of($item);
        $item->setCatalogueHidden(true);
        $tokens[] = VaultItemEditToken::of($item);
        $item->setTags([new VaultTag('work', $user), new VaultTag('alpha', $user)]);
        $tokens[] = VaultItemEditToken::of($item);

        self::assertCount(6, array_unique($tokens), 'each change gives a new token');

        $reordered = VaultItemEditToken::of($item->setTags([new VaultTag('alpha', $user), new VaultTag('work', $user)]));
        self::assertSame($tokens[5], $reordered, 'the tag order does not matter');
    }
}
