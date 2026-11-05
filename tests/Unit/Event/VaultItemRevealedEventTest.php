<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class VaultItemRevealedEventTest extends TestCase
{
    public function testCarriesMetadataOnly(): void
    {
        $event = new VaultItemRevealedEvent('7', 'item-1', VaultItemType::Login, ['password'], VaultItemRevealedEvent::CHANNEL_WEB);

        self::assertSame('nowo_vault.item_revealed', VaultEvents::ITEM_REVEALED);
        self::assertSame('7', $event->getUserId());
        self::assertSame('item-1', $event->getItemId());
        self::assertSame(VaultItemType::Login, $event->getItemType());
        self::assertSame(['password'], $event->getFields());
        self::assertSame('web', $event->getChannel());

        // No property can hold a secret value: ids, type, field names and channel only.
        $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($event))->getProperties());
        sort($properties);
        // 1.16.0 adds the purpose, the owner key and the time (still no value).
        self::assertSame(['channel', 'fields', 'itemId', 'itemType', 'occurredAt', 'ownerKey', 'purpose', 'userId'], $properties);
    }
}
