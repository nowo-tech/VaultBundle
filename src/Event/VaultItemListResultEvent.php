<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched after listing vault items; listeners may filter the items and total.
 */
final class VaultItemListResultEvent extends Event
{
    /**
     * Creates the event with the listed items and total.
     *
     * @param list<VaultItem> $items
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private array $items,
        private int $total,
    ) {
    }

    /**
     * Returns the user viewing the list.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the listed items.
     *
     * @return list<VaultItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Replaces the listed items.
     *
     * @param list<VaultItem> $items
     */
    public function setItems(array $items): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->items = $items;
    }

    /**
     * Returns the total item count.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Replaces the total item count.
     */
    public function setTotal(int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->total = $total;
    }
}
