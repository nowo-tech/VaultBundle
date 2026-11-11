<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ddd;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user of another identity domain (an admin next to the customers) whose ids may collide with vault users' ids.
 */
final readonly class OtherDomainUser implements UserInterface
{
    public function __construct(
        private string $id,
        private string $email,
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
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
