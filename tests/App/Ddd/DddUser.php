<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ddd;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user of a strict DDD host (1.17.0 tests): a plain PHP object with a string id, no Doctrine mapping.
 */
final readonly class DddUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $id,
        private string $email,
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
