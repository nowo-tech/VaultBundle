<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Exception\VaultItemCreateDeniedException;
use Nowo\VaultBundle\View\VaultExtensionTokenView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2.5.0: ITEM_CREATE_CHECK carries ids only, counts lazily once and accepts short reason codes only.
 */
final class VaultItemCreateCheckEventTest extends TestCase
{
    public function testAllowedByDefaultAndCountsOnce(): void
    {
        $calls = 0;
        $event = new VaultItemCreateCheckEvent('user:7', '7', null, '7', VaultItemType::Login, static function () use (&$calls): int {
            ++$calls;

            return 3;
        });

        self::assertSame('user:7', $event->getOwnerKey());
        self::assertSame('7', $event->getOwnerUserId());
        self::assertNull($event->getScope());
        self::assertSame('7', $event->getActorId());
        self::assertSame(VaultItemType::Login, $event->getType());
        self::assertFalse($event->isDenied());
        self::assertNull($event->getReason());
        self::assertSame(0, $calls, 'Nothing is counted until a listener asks.');
        self::assertSame(3, $event->itemCount());
        self::assertSame(3, $event->itemCount());
        self::assertSame(1, $calls);
    }

    public function testDenyStopsPropagation(): void
    {
        $event = new VaultItemCreateCheckEvent('scope:org-1', null, 'org-1', '7', VaultItemType::SecureNote, static fn (): int => 0);
        $event->deny('plan_limit');

        self::assertTrue($event->isDenied());
        self::assertSame('plan_limit', $event->getReason());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('org-1', $event->getScope());

        $exception = new VaultItemCreateDeniedException('plan_limit');
        self::assertSame('plan_limit', $exception->getReason());
        self::assertSame('The new vault item was refused.', $exception->getMessage());
    }

    #[DataProvider('invalidReasons')]
    public function testInvalidReasonsAreRefused(string $reason): void
    {
        $event = new VaultItemCreateCheckEvent('user:7', '7', null, '7', VaultItemType::Login, static fn (): int => 0);

        $this->expectException(InvalidArgumentException::class);
        $event->deny($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Plan'];
        yield 'space' => ['plan limit'];
        yield 'newline' => ["plan\n"];
        yield 'markup' => ['<b>'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    public function testTokenViewExpiry(): void
    {
        $now  = new DateTimeImmutable('2026-11-26 10:00:00');
        $view = new VaultExtensionTokenView('t-1', $now, $now, null);

        self::assertTrue($view->isExpiredAt($now));
        self::assertFalse($view->isExpiredAt($now->modify('-1 second')));
        self::assertFalse((new VaultExtensionTokenView('t-2', null, null, null))->isExpiredAt($now));
    }
}
