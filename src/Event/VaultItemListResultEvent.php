<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class VaultItemListResultEvent extends Event
{
    /**
     * @param list<VaultItem> $items
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private array $items,
        private int $total,
    ) {
    }

    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * @return list<VaultItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param list<VaultItem> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }
}
