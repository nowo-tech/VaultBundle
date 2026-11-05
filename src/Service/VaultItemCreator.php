<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates vault items with an encrypted payload (personal, or scope-owned since 1.16.0).
 */
final readonly class VaultItemCreator
{
    /**
     * Creates the service with the item repository, the payload cryptographer and the optional 1.16.0 collaborators
     * (ITEM_CHANGED events, scope checks).
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultPayloadCryptographerInterface $cryptographer,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?VaultAccessGuard $accessGuard = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Encrypts the payload and persists a new personal item.
     *
     * @param array<string, mixed> $payload
     */
    public function create(
        VaultItemType $type,
        string $title,
        UserInterface $creator,
        array $payload,
        ?VaultFolder $folder = null,
    ): VaultItem {
        $ciphertext = $this->cryptographer->encrypt($payload);
        $item       = new VaultItem($type, $title, $creator, $ciphertext, $folder);
        $this->itemRepository->save($item);
        $this->dispatchCreated($item, $creator);

        return $item;
    }

    /**
     * Encrypts the payload and persists a new item owned by a scope (1.16.0). The scope key comes from the route,
     * never from form data; the actor needs `create` in the scope, and the folder must belong to the same scope.
     *
     * @param array<string, mixed> $payload
     *
     * @throws VaultAccessDeniedException when the actor may not create in the scope
     * @throws VaultOwnerException when the folder belongs to another owner (or is trashed)
     */
    public function createInScope(
        UserInterface $actor,
        VaultItemType $type,
        string $title,
        string $scope,
        array $payload,
        ?VaultFolder $folder = null,
    ): VaultItem {
        $access = $this->accessGuard?->resolveScopeAccess($actor, $scope);
        if (!$access instanceof VaultScopeAccess || !$access->create) {
            throw new VaultAccessDeniedException();
        }

        if ($folder instanceof VaultFolder && ($folder->getOwnerScope() !== $scope || $folder->isDeleted())) {
            throw new VaultOwnerException('The folder belongs to another owner.');
        }

        $item = VaultItem::forScope($type, $title, $scope, $actor, $this->cryptographer->encrypt($payload), $folder);
        $this->itemRepository->save($item);
        $this->dispatchCreated($item, $actor);

        return $item;
    }

    private function dispatchCreated(VaultItem $item, UserInterface $actor): void
    {
        $this->eventDispatcher?->dispatch(new VaultItemChangeEvent(
            UserIdResolver::getId($actor),
            $item->getId(),
            $item->getOwner()->key(),
            VaultItemChangeEvent::KIND_CREATED,
            true,
            $this->clock?->now() ?? new DateTimeImmutable(),
        ), VaultEvents::ITEM_CHANGED);
    }
}
