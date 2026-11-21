<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Tag writes for a host (2.1.0, DDD-host checklist B1): item tags are set through
 * {@see VaultItemCommandsInterface::retag()}; this port deletes one of the actor's personal tags. Implemented by
 * {@see VaultTagCommands}.
 */
interface VaultTagCommandsInterface
{
    /**
     * Deletes a personal tag of the actor (the items keep their other tags).
     *
     * @throws VaultAccessDeniedException when the actor is unknown or the tag is not one of the actor's
     */
    public function delete(VaultUserRef $actor, string $tagId): void;
}
