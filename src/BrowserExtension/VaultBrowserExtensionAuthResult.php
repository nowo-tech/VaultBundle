<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Immutable outcome of a browser extension login attempt (authenticated user or failure reason).
 */
final readonly class VaultBrowserExtensionAuthResult
{
    private function __construct(
        private bool $success,
        private ?UserInterface $user = null,
        private ?string $failureReason = null,
    ) {
    }

    /**
     * Builds a successful result carrying the authenticated user.
     */
    public static function success(UserInterface $user): self
    {
        return new self(true, $user);
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
     */
    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    /**
     * Returns the failure reason, or null when the attempt succeeded.
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
