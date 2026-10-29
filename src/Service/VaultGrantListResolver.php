<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches the grant list query event to collect the grantees offered when sharing.
 */
final readonly class VaultGrantListResolver
{
    /**
     * Creates the resolver with the event dispatcher.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Collects the grantee choices for sharing an item.
     */
    public function resolveForItem(UserInterface $grantedBy, VaultItem $item): VaultGrantListQueryEvent
    {
        return $this->resolve($grantedBy, VaultResourceType::Item, $item);
    }

    /**
     * Collects the grantee choices for sharing a folder.
     */
    public function resolveForFolder(UserInterface $grantedBy, VaultFolder $folder): VaultGrantListQueryEvent
    {
        return $this->resolve($grantedBy, VaultResourceType::Folder, $folder);
    }

    private function resolve(
        UserInterface $grantedBy,
        VaultResourceType $resourceType,
        VaultItem|VaultFolder $resource,
    ): VaultGrantListQueryEvent {
        $event = new VaultGrantListQueryEvent(
            $grantedBy,
            $resourceType,
            $resource->getId(),
            $resource,
        );
        $this->eventDispatcher->dispatch($event, VaultEvents::GRANT_LIST_QUERY);

        return $event;
    }
}
