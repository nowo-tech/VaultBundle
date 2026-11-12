<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeFolderRepositoryInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates, renames and looks up vault folders.
 */
final readonly class VaultFolderService
{
    /**
     * Creates the service with the folder repository.
     */
    public function __construct(
        private VaultFolderRepositoryInterface $folderRepository,
        private ?VaultAccessGuard $accessGuard = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Creates a folder owned by a scope (1.16.0). The actor needs `create` in the scope; a parent folder must belong
     * to the same scope.
     *
     * @throws VaultAccessDeniedException when the actor may not create in the scope
     * @throws VaultOwnerException when the parent belongs to another owner
     */
    public function createInScope(UserInterface $actor, string $name, string $scope, ?VaultFolder $parent = null): VaultFolder
    {
        $access = $this->accessGuard?->resolveScopeAccess($actor, $scope);
        if (!$access instanceof VaultScopeAccess || !$access->create) {
            throw new VaultAccessDeniedException();
        }

        if ($parent instanceof VaultFolder && $parent->getOwnerScope() !== $scope) {
            throw new VaultOwnerException('The parent folder belongs to another owner.');
        }

        $folder = VaultFolder::forScope($name, $scope, $actor, $parent);
        $this->folderRepository->save($folder);
        $this->changed($folder, VaultFolderChangeEvent::KIND_CREATED, $actor);

        return $folder;
    }

    /**
     * Returns the scope's active folders (1.16.0).
     *
     * @return list<VaultFolder>
     */
    public function listForScope(string $scope): array
    {
        if (!$this->folderRepository instanceof VaultScopeFolderRepositoryInterface) {
            throw new LogicException('Scope folders need a folder repository implementing VaultScopeFolderRepositoryInterface.');
        }

        return $this->folderRepository->findByScope($scope);
    }

    /**
     * Creates and persists a folder.
     */
    public function create(string $name, object $creator, ?VaultFolder $parent = null): VaultFolder
    {
        $folder = new VaultFolder($name, $creator, $parent);
        $this->folderRepository->save($folder);
        $this->changed($folder, VaultFolderChangeEvent::KIND_CREATED, $creator);

        return $folder;
    }

    /**
     * Renames and persists a folder.
     */
    public function rename(VaultFolder $folder, string $name): VaultFolder
    {
        $folder->setName($name);
        $this->folderRepository->save($folder);
        $this->changed($folder, VaultFolderChangeEvent::KIND_RENAMED, null);

        return $folder;
    }

    /**
     * Returns the creator's active folders.
     *
     * @return list<VaultFolder>
     */
    public function listForCreator(object $creator): array
    {
        return $this->folderRepository->findByCreator($creator);
    }

    /**
     * FOLDER_CHANGED after the vault's flush (1.19.0): ids only.
     */
    private function changed(VaultFolder $folder, string $kind, ?object $actor): void
    {
        $this->eventDispatcher?->dispatch(
            new VaultFolderChangeEvent($actor !== null ? UserIdResolver::getId($actor) : null, $folder->getId(), $folder->getOwner()->key(), $kind, $this->clock?->now() ?? new DateTimeImmutable()),
            VaultEvents::FOLDER_CHANGED,
        );
    }

    /**
     * Finds a folder by id.
     */
    public function find(string $id): ?VaultFolder
    {
        return $this->folderRepository->findById($id);
    }
}
