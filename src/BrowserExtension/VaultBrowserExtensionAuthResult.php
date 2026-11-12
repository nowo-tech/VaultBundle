<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use InvalidArgumentException;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Immutable outcome of a browser extension login attempt (authenticated user or failure reason).
 *
 * 1.19.0 (REQ-ARCH-001 rule 3): a successful result can be built from a framework-free {@see VaultUserRef}
 * ({@see self::authenticated()}) and read as one ({@see self::getUserRef()}). With a user object, that object is the
 * user (the vault's class policy applies, as in 1.18). A reference by id alone carries no trust (audit NJ-1): the vault
 * looks the id up again through its user locator port and uses that answer's identity and roles (never the
 * reference's); an unknown id gets no token.
 */
final readonly class VaultBrowserExtensionAuthResult
{
    private function __construct(
        private bool $success,
        private ?UserInterface $user = null,
        private ?string $failureReason = null,
        private ?VaultUserRef $ref = null,
    ) {
    }

    /**
     * Builds a successful result carrying the authenticated user.
     *
     * @deprecated since 1.19.0, use authenticated() with a VaultUserRef (no Symfony type); removed in 2.0
     */
    public static function success(UserInterface $user): self
    {
        return new self(true, $user);
    }

    /**
     * Builds a successful result for the referenced user (1.19.0): its user object, or only its id (the vault resolves
     * it again through the user locator port; the reference's roles are ignored).
     *
     * @throws InvalidArgumentException when the reference has neither a user object nor an id
     */
    public static function authenticated(VaultUserRef $user): self
    {
        $object = VaultUserRefs::toUser($user);
        if (!$object instanceof UserInterface && $user->id === null) {
            throw new InvalidArgumentException('An authenticated vault user needs a user object or an id.');
        }

        return new self(true, $object, null, $object instanceof UserInterface ? null : $user);
    }

    /**
     * Builds a failed result carrying a non-sensitive failure reason.
     */
    public static function failure(string $reason): self
    {
        return new self(false, failureReason: $reason);
    }

    /**
     * Whether the login attempt succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Returns the authenticated user, or null when the attempt failed.
     *
     * @deprecated since 1.19.0, use getUserRef(); removed in 2.0
     */
    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    /**
     * The authenticated user as a framework-free reference, or null when the attempt failed (1.19.0).
     */
    public function getUserRef(): ?VaultUserRef
    {
        return $this->ref ?? VaultUserRefs::fromNullableUser($this->user);
    }

    /**
     * Returns the failure reason, or null when the attempt succeeded.
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
