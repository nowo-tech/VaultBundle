<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Creates vault items with an encrypted payload.
 */
final readonly class VaultItemCreator
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
     * Encrypts the payload and persists a new item.
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

        return $item;
    }
}
