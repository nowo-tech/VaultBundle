<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;

/**
 * Updates vault items and re-encrypts their payload.
 */
final readonly class VaultItemUpdater
{
    /**
     * Creates the service with the item repository and the payload cryptographer.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultPayloadCryptographerInterface $cryptographer,
    ) {
    }

    /**
     * Updates title, encrypted payload and folder of an item and persists it.
     *
     * @param array<string, mixed> $payload
     */
    public function update(VaultItem $item, string $title, array $payload, ?VaultFolder $folder = null): VaultItem
    {
        $item->setTitle($title);
        $item->setCiphertext($this->cryptographer->encrypt($payload));
        $item->setFolder($folder);
        $this->itemRepository->save($item);

        return $item;
    }
}
