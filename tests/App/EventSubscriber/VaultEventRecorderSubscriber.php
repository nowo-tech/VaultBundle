<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Nowo\VaultBundle\Event\VaultEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Records the 1.16.0 vault events for the functional tests (a static list: services are reset between requests).
 */
final class VaultEventRecorderSubscriber implements EventSubscriberInterface
{
    /** @var list<array{0: string, 1: Event}> */
    public static array $events = [];

    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach ([
            VaultEvents::GRANT_CREATED,
            VaultEvents::GRANT_UPDATED,
            VaultEvents::GRANT_REVOKED,
            VaultEvents::GRANT_EXPIRED,
            VaultEvents::ITEM_CHANGED,
        ] as $name) {
            $events[$name] = ['record', -1000];
        }

        return $events;
    }

    public function record(Event $event, string $name): void
    {
        self::$events[] = [$name, $event];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], self::$events);
    }
}
