<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Closure;
use InvalidArgumentException;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCommandsInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function preg_match;

/**
 * ITEM_CREATE_CHECK (2.5.0, Beacon spec 185 FR-020): the host may refuse a new item, e.g. when the account reached the
 * item limit of its plan. Dispatched by every creation path (the personal and scope *New item* pages and the
 * {@see VaultItemCommandsInterface} create calls), inside the item's write unit and before
 * anything is encrypted or written. Without a listener every creation is allowed (no limit).
 *
 * The event carries ids only (REQ-ARCH-001 rules 3 and 4): the owner key (`user:<id>` or `scope:<key>`), the actor and
 * the item type; never the title or a payload field.
 *
 * {@see itemCount()} counts the items the owner already stores (trash included), with a locking read inside the
 * write unit, so two creations racing for the last free place are serialised (REQ-RUNTIME-003). It runs only when a
 * listener calls it, once per event.
 */
final class VaultItemCreateCheckEvent extends Event
{
    /** Longest reason code. */
    public const MAX_REASON_LENGTH = 64;

    private ?string $reason = null;

    private ?int $count = null;

    /**
     * @param Closure(): int $counter counts the owner's stored items (called at most once)
     */
    public function __construct(
        private readonly string $ownerKey,
        private readonly ?string $ownerUserId,
        private readonly ?string $scope,
        private readonly ?string $actorId,
        private readonly VaultItemType $type,
        private readonly Closure $counter,
    ) {
    }

    /**
     * `user:<id>` for a personal item, `scope:<key>` for a scope item.
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * The vault user id of the personal vault the item goes to, or null for a scope item.
     */
    public function getOwnerUserId(): ?string
    {
        return $this->ownerUserId;
    }

    /**
     * The scope key of a scope item, or null for a personal item.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * The vault user id of the person creating the item (null when the user exposes no id).
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getType(): VaultItemType
    {
        return $this->type;
    }

    /**
     * Items the owner stores now, trash included (a trashed item can be restored without a new check).
     */
    public function itemCount(): int
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        return $this->count ??= ($this->counter)();
    }

    /**
     * Refuses the creation. `$reason` is a short machine code (`[a-z0-9_.-]`, at most 64 characters, e.g.
     * `plan_limit`) for your logs and API answers; the vault page shows its own translated message, never the code.
     *
     * @throws InvalidArgumentException for an invalid code
     */
    public function deny(string $reason = 'denied'): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,' . self::MAX_REASON_LENGTH . '}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('The refusal reason must be a short code ([a-z0-9_.-], 1 to 64 characters).');
        }

        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->reason = $reason;
        $this->stopPropagation();
    }

    public function isDenied(): bool
    {
        return $this->reason !== null;
    }

    /**
     * The refusal code, or null when the creation is allowed.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
