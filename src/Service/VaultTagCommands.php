<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Internal\VaultCommandsTrait;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * The vault's {@see VaultTagCommandsInterface} (2.1.0) over {@see VaultTagService}. Stateless.
 */
final readonly class VaultTagCommands implements VaultTagCommandsInterface
{
    use VaultCommandsTrait;

    public function __construct(
        private VaultTagService $tags,
        private VaultUserRefResolver $refs,
        private VaultUserReferenceResolverInterface $references,
        private ?VaultWriteUnit $writeUnit = null,
    ) {
    }

    public function delete(VaultUserRef $actor, string $tagId): void
    {
        $user = $this->actor($actor);

        try {
            $this->inUnit(function () use ($user, $tagId): void {
                $this->tags->deleteForCreator($user, $tagId);
            });
        } catch (InvalidArgumentException) {
            // Unknown, or not one of the actor's tags: one answer for both.
            throw new VaultAccessDeniedException();
        }
    }
}
