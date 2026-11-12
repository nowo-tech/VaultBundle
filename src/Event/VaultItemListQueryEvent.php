<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched before listing vault items; listeners may change the list subject or override the result.
 */
final class VaultItemListQueryEvent extends Event
{
    private bool $hasOverride = false;

    /** @var list<VaultItem> */
    private array $overrideItems = [];

    private int $overrideTotal = 0;

    /**
     * Creates the event for a viewer and the list filters.
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private UserInterface $listSubject,
        private readonly ?string $folderId = null,
        private readonly bool $trashOnly = false,
        private readonly bool $sharedOnly = false,
    ) {
    }

    /**
     * Returns the user viewing the list.
     *
     * @deprecated since 1.19.0, use getViewerRef() / getViewerId() (no Symfony type, REQ-ARCH-001 rule 3); removed in 2.0
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the user whose items are listed.
     *
     * @deprecated since 1.19.0, use getListSubjectRef() / getListSubjectId(); removed in 2.0
     */
    public function getListSubject(): UserInterface
    {
        return $this->listSubject;
    }

    /**
     * Changes the user whose items are listed.
     *
     * @deprecated since 1.19.0, use setListSubjectRef(); removed in 2.0
     */
    public function setListSubject(UserInterface $listSubject): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->listSubject = $listSubject;
    }

    /**
     * Returns the folder filter, or null.
     */
    public function getFolderId(): ?string
    {
        return $this->folderId;
    }

    /**
     * Whether only trashed items are listed.
     */
    public function isTrashOnly(): bool
    {
        return $this->trashOnly;
    }

    /**
     * Whether only items shared with the viewer are listed.
     */
    public function isSharedOnly(): bool
    {
        return $this->sharedOnly;
    }

    /**
     * Whether a listener overrode the result.
     */
    public function hasOverride(): bool
    {
        return $this->hasOverride;
    }

    /**
     * Returns the override items.
     *
     * @return list<VaultItem>
     */
    public function getOverrideItems(): array
    {
        return $this->overrideItems;
    }

    /**
     * Returns the override total.
     */
    public function getOverrideTotal(): int
    {
        return $this->overrideTotal;
    }

    /**
     * Overrides the list result with the given items and total.
     *
     * @param list<VaultItem> $items
     */
    public function overrideResult(array $items, int $total): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->hasOverride = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideItems = $items;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideTotal = $total;
    }

    /**
     * The viewer as a framework-free reference (1.19.0, REQ-ARCH-001 rules 3 and 4).
     */
    public function getViewerRef(): VaultUserRef
    {
        return VaultUserRefs::fromUser($this->viewer);
    }

    /**
     * The viewer's vault user id, or null when it has none (1.19.0).
     */
    public function getViewerId(): ?string
    {
        return $this->getViewerRef()->id;
    }

    /**
     * The user whose items are listed, as a framework-free reference (1.19.0).
     */
    public function getListSubjectRef(): VaultUserRef
    {
        return VaultUserRefs::fromUser($this->listSubject);
    }

    /**
     * The vault user id of the user whose items are listed, or null (1.19.0).
     */
    public function getListSubjectId(): ?string
    {
        return $this->getListSubjectRef()->id;
    }

    /**
     * Same as {@see self::setListSubject()} with a framework-free reference (1.19.0): its user object, or the vault's
     * stand-in for its id.
     *
     * @throws InvalidArgumentException when the reference has neither a user object nor an id
     */
    public function setListSubjectRef(VaultUserRef $listSubject): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->listSubject = VaultUserRefs::toUser($listSubject) ?? throw new InvalidArgumentException('The list subject needs a user object or an id.');
    }
}
