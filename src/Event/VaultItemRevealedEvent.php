<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
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

    /** Purpose of a web reveal (Show) (1.16.0). */
    public const PURPOSE_REVEAL = 'reveal';

    /** Purpose of a web copy to the clipboard (1.16.0, dispatched as ITEM_COPIED). */
    public const PURPOSE_COPY = 'copy';

    /** Purpose of a credential returned to the browser extension /logins (1.16.0). */
    public const PURPOSE_AUTOFILL = 'autofill';

    /** Purpose of an attachment download (1.16.0, dispatched as ITEM_ATTACHMENT_DOWNLOADED). */
    public const PURPOSE_ATTACHMENT = 'attachment';

    /**
     * @param list<string> $fields payload field names returned (e.g. ["password"]), never their values
     */
    public function __construct(
        private readonly ?string $userId,
        private readonly string $itemId,
        private readonly VaultItemType $itemType,
        private readonly array $fields,
        private readonly string $channel,
        private readonly ?string $purpose = null,
        private readonly ?string $ownerKey = null,
        private readonly ?DateTimeImmutable $occurredAt = null,
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

    /**
     * Why the value left the vault (1.16.0): `reveal`, `copy`, `autofill` or `attachment`. Null only for events built
     * by 1.15.2 code: read it as `reveal` (web) / `autofill` (extension).
     */
    public function getPurpose(): string
    {
        return $this->purpose ?? ($this->channel === self::CHANNEL_EXTENSION ? self::PURPOSE_AUTOFILL : self::PURPOSE_REVEAL);
    }

    /**
     * Owner key of the item (`user:<id>` / `scope:<key>`) (1.16.0), or null when not provided.
     */
    public function getOwnerKey(): ?string
    {
        return $this->ownerKey;
    }

    /**
     * When the value left the vault (1.16.0), or null when not provided.
     */
    public function getOccurredAt(): ?DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
