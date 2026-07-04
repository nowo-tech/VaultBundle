<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class VaultItemCreator
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultPayloadCryptographerInterface $cryptographer,
    ) {
    }

    /**
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
