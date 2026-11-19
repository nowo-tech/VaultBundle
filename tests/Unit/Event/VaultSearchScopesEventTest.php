<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use Nowo\VaultBundle\Event\VaultSearchScopesEvent;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VaultSearchScopesEvent::class)]
final class VaultSearchScopesEventTest extends TestCase
{
    public function testNoScopeIsSearchedUntilAListenerNamesIt(): void
    {
        $event = new VaultSearchScopesEvent(VaultUserRef::ofId('u-1', 'alice'));

        self::assertSame('u-1', $event->getUserId());
        self::assertSame('alice', $event->getUserRef()->identifier);
        self::assertFalse($event->isNamed());
        self::assertNull($event->getScopes());
        self::assertFalse($event->isSearched('proj-a'), 'Fail closed.');
        self::assertFalse($event->isSearched('Not A Scope'), 'A malformed key is never searched.');
    }

    public function testLimitToIntersectsAndIgnoresMalformedKeys(): void
    {
        $event = new VaultSearchScopesEvent(VaultUserRef::ofId('u-1'));
        $event->limitTo(['proj-a', 'proj-b', 'proj-a', '../x', 'UPPER']);
        self::assertSame(['proj-a', 'proj-b'], $event->getScopes());
        $event->limitTo(['proj-b', 'proj-c']);
        self::assertSame(['proj-b'], $event->getScopes(), 'A later listener can only narrow.');
        self::assertTrue($event->isNamed());
        self::assertTrue($event->isSearched('proj-b'));
        self::assertFalse($event->isSearched('proj-a'));
    }

    public function testExcludeWinsAndExcludeAllSearchesNoScope(): void
    {
        $event = new VaultSearchScopesEvent(VaultUserRef::ofId('u-1'));
        $event->exclude(['proj-a', 'bad key']);
        $event->exclude(['proj-a', 'proj-c']);
        self::assertSame(['proj-a', 'proj-c'], $event->getExcluded());
        self::assertNull($event->getScopes(), 'Excluding alone names no scope.');
        self::assertFalse($event->isSearched('proj-a'));
        self::assertFalse($event->isSearched('proj-b'));

        $event->limitTo(['proj-a', 'proj-b']);
        self::assertSame(['proj-b'], $event->getScopes());
        self::assertTrue($event->isSearched('proj-b'));
        self::assertFalse($event->isSearched('proj-a'), 'Excluded wins.');

        $event->excludeAll();
        self::assertSame([], $event->getScopes());
        self::assertFalse($event->isSearched('proj-b'));
    }
}
