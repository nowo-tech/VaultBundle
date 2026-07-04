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

final readonly class VaultGrantListResolver
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function resolveForItem(UserInterface $grantedBy, VaultItem $item): VaultGrantListQueryEvent
    {
        return $this->resolve($grantedBy, VaultResourceType::Item, $item);
    }

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
