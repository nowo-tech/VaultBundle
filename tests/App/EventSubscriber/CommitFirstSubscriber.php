<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Test host listener (REQ-RUNTIME-003): with the header {@see self::HEADER}, commits a write on the vault's connection
 * before the controller runs, as a host or another bundle may do earlier in the same request.
 */
final readonly class CommitFirstSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Test-Commit-First';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->headers->has(self::HEADER)) {
            return;
        }

        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE vault_folders SET name = name WHERE 1 = 0');
        $this->connection->commit();
    }
}
