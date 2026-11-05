<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Simulates an actor losing Share between two checks of one request (inactive unless a test sets $denyAfter): the
 * first $denyAfter Share checks pass, the next ones are denied.
 */
final class ShareRevocationSubscriber implements EventSubscriberInterface
{
    public static ?int $denyAfter = null;

    private static int $seen = 0;

    public static function getSubscribedEvents(): array
    {
        return [
            VaultEvents::ITEM_ACCESS_CHECK   => ['onCheck', -100],
            VaultEvents::FOLDER_ACCESS_CHECK => ['onCheck', -100],
        ];
    }

    public static function arm(int $denyAfter): void
    {
        self::$denyAfter = $denyAfter;
        self::$seen      = 0;
    }

    public function onCheck(VaultItemAccessCheckEvent|VaultFolderAccessCheckEvent $event): void
    {
        if (self::$denyAfter === null || $event->getAction() !== VaultAccessAction::Share) {
            return;
        }

        if (++self::$seen > self::$denyAfter) {
            $event->deny();
        }
    }
}
