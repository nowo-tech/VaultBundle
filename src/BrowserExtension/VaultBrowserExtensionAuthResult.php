<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class VaultBrowserExtensionAuthResult
{
    private function __construct(
        private bool $success,
        private ?UserInterface $user = null,
        private ?string $failureReason = null,
    ) {
    }

    public static function success(UserInterface $user): self
    {
        return new self(true, $user);
    }

    public static function failure(string $reason): self
    {
        return new self(false, failureReason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
}
