<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;

final readonly class VaultFolderService
{
    public function __construct(
        private VaultFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function create(string $name, object $creator, ?VaultFolder $parent = null): VaultFolder
    {
        $folder = new VaultFolder($name, $creator, $parent);
        $this->folderRepository->save($folder);

        return $folder;
    }

    public function rename(VaultFolder $folder, string $name): VaultFolder
    {
        $folder->setName($name);
        $this->folderRepository->save($folder);

        return $folder;
    }

    /**
     * @return list<VaultFolder>
     */
    public function listForCreator(object $creator): array
    {
        return $this->folderRepository->findByCreator($creator);
    }

    public function find(string $id): ?VaultFolder
    {
        return $this->folderRepository->findById($id);
    }
}
