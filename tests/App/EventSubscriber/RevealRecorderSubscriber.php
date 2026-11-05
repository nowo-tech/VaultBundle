<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records ITEM_REVEALED events for the functional tests (a static list: services are reset between test requests).
 */
final class RevealRecorderSubscriber implements EventSubscriberInterface
{
    /** @var list<VaultItemRevealedEvent> */
    public static array $events = [];

    public static function getSubscribedEvents(): array
    {
        return [VaultEvents::ITEM_REVEALED => 'onRevealed'];
    }

    public function onRevealed(VaultItemRevealedEvent $event): void
    {
        self::$events[] = $event;
    }
}
