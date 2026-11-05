<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Enum\VaultItemType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when decrypted secret values of an item are returned to a user: one field revealed in the manage UI
 * (channel "web") or one login returned by the browser extension /logins lookup (channel "extension").
 *
 * Metadata only, for the host audit trail: the event never carries a value (no property can hold one).
 */
final class VaultItemRevealedEvent extends Event
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_EXTENSION = 'extension';

    /**
     * @param list<string> $fields payload field names returned (e.g. ["password"]), never their values
     */
    public function __construct(
        private readonly ?string $userId,
        private readonly string $itemId,
        private readonly VaultItemType $itemType,
        private readonly array $fields,
        private readonly string $channel,
    ) {
    }

    /**
     * Returns the id of the user the values were returned to (null when the user object has no id).
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Returns the item id.
     */
    public function getItemId(): string
    {
        return $this->itemId;
    }

    /**
     * Returns the item type.
     */
    public function getItemType(): VaultItemType
    {
        return $this->itemType;
    }

    /**
     * Returns the names of the payload fields returned.
     *
     * @return list<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns the channel: "web" (field reveal) or "extension" (/logins autofill lookup).
     */
    public function getChannel(): string
    {
        return $this->channel;
    }
}
