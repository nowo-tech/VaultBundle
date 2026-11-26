<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultExtensionTokenView;

/**
 * The browser extension tokens of one user, for an *Account → Security* page (2.5.0, Beacon spec 185 FR-022,
 * REQ-ARCH-001 rules 1 and 3): list them and sign a device out. Implemented by {@see VaultExtensionTokenCommands}.
 *
 * Every call works on the tokens of `$user` only: a token id of another user, an unknown id and an id revoked meanwhile
 * all give the same answer (`false`), so an id never confirms that a token exists. A reference by id only is looked up
 * again through the user directory port (audit NJ-1). A revocation emits {@see VaultEvents::EXTENSION_TOKEN_REVOKED}
 * after its commit. Works with the browser extension off too (rows of an earlier period are listed and revoked).
 *
 * Errors: {@see VaultAccessDeniedException} when the user is unknown or has no id; {@see TransientStorageFailure} for a
 * lock conflict (retry).
 */
interface VaultExtensionTokenCommandsInterface
{
    /**
     * The user's tokens, oldest first, expired ones included until the purge command removes them.
     *
     * @return list<VaultExtensionTokenView>
     */
    public function listForUser(VaultUserRef $user): array;

    /**
     * Deletes one of the user's tokens: the device using it is signed out at its next request. False when the id is not
     * one of the user's current tokens.
     */
    public function revoke(VaultUserRef $user, string $tokenId): bool;

    /**
     * Deletes every token of the user (sign out all devices) and returns how many were deleted.
     */
    public function revokeAll(VaultUserRef $user): int;
}
