<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Nowo\VaultBundle\Event\VaultEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function constant;
use function defined;
use function krsort;

/**
 * Test listeners that survive the kernel reboot between test requests: tests register callables in a static registry
 * ({@see self::add()}); this subscriber forwards the vault events to them. Inactive while the registry is empty.
 */
final class ScopesKernelListeners implements EventSubscriberInterface
{
    /** Event constants forwarded to the registered callables. */
    private const EVENTS = [
        'ITEM_ACCESS_CHECK', 'ITEM_REVEALED', 'ITEM_COPIED', 'ITEM_ATTACHMENT_DOWNLOADED', 'ITEM_FIELD_REVEAL_CHECK',
        'REVEAL_THROTTLED', 'CATALOGUE_ACCESS_CHECK', 'HEALTH_ACCESS_CHECK', 'HEALTH_REPORT_GENERATED',
        'ITEMS_EXPORTED', 'PERSONAL_DATA_ERASED',
    ];

    /** @var array<string, array<int, list<callable>>> event name => priority => callables */
    private static array $listeners = [];

    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach (self::EVENTS as $constant) {
            if (defined(VaultEvents::class . '::' . $constant)) {
                $events[(string) constant(VaultEvents::class . '::' . $constant)] = 'forward';
            }
        }

        return $events;
    }

    public static function add(string $name, callable $listener, int $priority = 0): void
    {
        self::$listeners[$name][$priority][] = $listener;
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }

    public function forward(Event $event, string $name): void
    {
        $byPriority = self::$listeners[$name] ?? [];
        krsort($byPriority);
        foreach ($byPriority as $listeners) {
            foreach ($listeners as $listener) {
                $listener($event, $name);
            }
        }
    }
}
