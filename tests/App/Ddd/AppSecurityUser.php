<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ddd;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user that is no Doctrine entity but carries the id of the `user_class` entity (1.17.0 tests): listed in
 * `nowo_vault.accepted_user_classes`, it maps to a reference of that entity.
 */
final readonly class AppSecurityUser implements UserInterface
{
    public function __construct(
        private int $id,
        private string $email,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
