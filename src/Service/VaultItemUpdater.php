<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;

final readonly class VaultItemUpdater
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultPayloadCryptographerInterface $cryptographer,
    ) {
    }

    /**
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
