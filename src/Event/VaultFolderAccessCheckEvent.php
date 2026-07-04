<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultFolder;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class VaultFolderAccessCheckEvent extends Event
{
    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultFolder $folder,
        private readonly VaultAccessAction $action,
        private bool $granted,
    ) {
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function getFolder(): VaultFolder
    {
        return $this->folder;
    }

    public function getAction(): VaultAccessAction
    {
        return $this->action;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function grant(): void
    {
        $this->granted = true;
    }

    public function deny(): void
    {
        $this->granted = false;
    }
}
