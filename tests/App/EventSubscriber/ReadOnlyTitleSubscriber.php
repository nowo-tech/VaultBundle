<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function str_starts_with;

/**
 * Test-kernel listener: items titled "[ro] ..." are read-only for everyone but their creator.
 */
final class ReadOnlyTitleSubscriber implements EventSubscriberInterface
{
    public const PREFIX = '[ro] ';

    public static function getSubscribedEvents(): array
    {
        return [VaultEvents::ITEM_READ_ONLY_RESOLVE => 'onReadOnlyResolve'];
    }

    public function onReadOnlyResolve(VaultItemReadOnlyEvent $event): void
    {
        $item = $event->getItem();
        if (str_starts_with($item->getTitle(), self::PREFIX) && !UserIdResolver::isSameUser($item->getCreator(), $event->getUser())) {
            $event->markReadOnly();
        }
    }
}
