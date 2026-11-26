<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Nowo\VaultBundle\Service\VaultExtensionTokenCommandsInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * EXTENSION_TOKEN_REVOKED (2.5.0): browser extension tokens of one user were deleted through
 * {@see VaultExtensionTokenCommandsInterface}, after the commit. Ids and the time only, never
 * a token or its hash (audit rows of *Account → Security*).
 */
final class VaultExtensionTokenRevokedEvent extends Event
{
    /** One token revoked by the user. */
    public const REASON_ONE = 'revoked';

    /** Every token of the user revoked at once. */
    public const REASON_ALL = 'revoked_all';

    /**
     * @param list<string> $tokenIds the deleted token ids
     */
    public function __construct(
        private readonly string $userId,
        private readonly array $tokenIds,
        private readonly string $reason,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * The vault user id the tokens belonged to.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * @return list<string>
     */
    public function getTokenIds(): array
    {
        return $this->tokenIds;
    }

    /**
     * {@see self::REASON_ONE} or {@see self::REASON_ALL}.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
