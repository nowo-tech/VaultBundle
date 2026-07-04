<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class VaultItemListQueryEvent extends Event
{
    private bool $hasOverride = false;

    /** @var list<VaultItem> */
    private array $overrideItems = [];

    private int $overrideTotal = 0;

    public function __construct(
        private readonly UserInterface $viewer,
        private UserInterface $listSubject,
        private readonly ?string $folderId = null,
        private readonly bool $trashOnly = false,
        private readonly bool $sharedOnly = false,
    ) {
    }

    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    public function getListSubject(): UserInterface
    {
        return $this->listSubject;
    }

    public function setListSubject(UserInterface $listSubject): void
    {
        $this->listSubject = $listSubject;
    }

    public function getFolderId(): ?string
    {
        return $this->folderId;
    }

    public function isTrashOnly(): bool
    {
        return $this->trashOnly;
    }

    public function isSharedOnly(): bool
    {
        return $this->sharedOnly;
    }

    public function hasOverride(): bool
    {
        return $this->hasOverride;
    }

    /**
     * @return list<VaultItem>
     */
    public function getOverrideItems(): array
    {
        return $this->overrideItems;
    }

    public function getOverrideTotal(): int
    {
        return $this->overrideTotal;
    }

    /**
     * @param list<VaultItem> $items
     */
    public function overrideResult(array $items, int $total): void
    {
        $this->hasOverride   = true;
        $this->overrideItems = $items;
        $this->overrideTotal = $total;
    }
}
