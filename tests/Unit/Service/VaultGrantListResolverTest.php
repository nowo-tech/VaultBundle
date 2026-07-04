<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Service\VaultGrantListResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class VaultGrantListResolverTest extends TestCase
{
    public function testResolveForItemDispatchesEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::GRANT_LIST_QUERY, static function (VaultGrantListQueryEvent $event): void {
            $event->addGrantee(new VaultGranteeChoice(GranteeType::User, 'u1', 'Alice'));
        });

        $resolver = new VaultGrantListResolver($dispatcher);
        $user     = new TestUser('owner');
        $item     = new VaultItem(VaultItemType::Login, 'Demo', $user, 'cipher');

        $event = $resolver->resolveForItem($user, $item);

        self::assertSame($user, $event->getGrantedBy());
        self::assertSame(VaultResourceType::Item, $event->getResourceType());
        self::assertSame($item->getId(), $event->getResourceId());
        self::assertSame($item, $event->getItem());
        self::assertNull($event->getFolder());
        self::assertTrue($event->isGranteeAllowed(GranteeType::User, 'u1'));
    }

    public function testResolveForFolderDispatchesEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::GRANT_LIST_QUERY, static function (VaultGrantListQueryEvent $event): void {
            $event->addGrantee(new VaultGranteeChoice(GranteeType::Team, 'group-1', 'Sales group'));
        });

        $resolver = new VaultGrantListResolver($dispatcher);
        $user     = new TestUser('owner');
        $folder   = new VaultFolder('Docs', $user);

        $event = $resolver->resolveForFolder($user, $folder);

        self::assertSame(VaultResourceType::Folder, $event->getResourceType());
        self::assertSame($folder, $event->getFolder());
        self::assertNull($event->getItem());
        self::assertSame('Sales group', $event->getLabelFor(GranteeType::Team, 'group-1'));
    }
}
